<?php
namespace UCASSer\Campus;

use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

class Aggregation
{
    public const CATEGORIES = ['notice' => '通知', 'activity' => '活动', 'lecture' => '讲座', 'opportunity' => '机会', 'other' => '其他'];

    public function __construct(public ConnectionInterface $db) {}

    private function text(array $input, string $key, int $min, int $max): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value) || mb_strlen(trim($value)) < $min || mb_strlen($value) > $max) throw new ValidationException([$key => "请填写 $min–$max 字"]);
        return trim($value);
    }

    public function cleanUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') return null;
        $url = trim($url);
        if (mb_strlen($url) > 1024 || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new ValidationException(['source_url' => '请填写有效的 HTTP(S) 网址']);
        }
        return $url;
    }

    public function urlHash(?string $url): ?string
    {
        if (!$url) return null;
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $scheme = strtolower($parts['scheme'] ?? '');
        $path = rtrim($parts['path'] ?? '/', '/') ?: '/';
        return hash('sha256', $scheme.'://'.$host.$path.(isset($parts['query']) ? '?'.$parts['query'] : ''));
    }

    public function submit(User $actor, array $input): int
    {
        AccountAccess::assertConfirmed($actor);
        $origin = $input['origin'] ?? '';
        if (!in_array($origin, ['public_web', 'private_tip'], true)) throw new ValidationException(['origin' => '请选择公开网页或非公开线索']);
        $url = $this->cleanUrl($this->text($input, 'source_url', 0, 1024));
        if ($origin === 'public_web' && !$url) throw new ValidationException(['source_url' => '公开网页投稿需要原始网址']);
        if (!is_bool($input['show_submitter'] ?? null)) throw new ValidationException(['show_submitter' => '请选择是否公开投稿昵称']);
        $category = $input['category'] ?? '';
        if (!isset(self::CATEGORIES[$category])) throw new ValidationException(['category' => '请选择有效分类']);
        $title = $this->text($input, 'title', 3, 180);
        $summary = $this->text($input, 'summary', 8, 1000);
        $name = $this->text($input, 'source_name', 2, 120);
        $urlHash = $this->urlHash($url);
        $suggested = $urlHash ? $this->db->table('campus_aggregation_candidates')->where('url_hash', $urlHash)->whereNotNull('item_id')->orderByDesc('id')->value('item_id') : null;
        $now = gmdate('Y-m-d H:i:s');
        return $this->db->table('campus_aggregation_candidates')->insertGetId([
            'source_id' => null, 'submitter_id' => $actor->id, 'item_id' => $suggested,
            'origin' => 'manual', 'visibility' => $origin === 'public_web' ? 'public' : 'members', 'trust' => 'unofficial',
            'status' => 'pending', 'fingerprint' => hash('sha256', random_bytes(32)), 'url_hash' => $urlHash,
            'content_hash' => hash('sha256', $title."\n".$summary), 'source_name' => $name, 'source_url' => $url,
            'title' => $title, 'summary' => $summary, 'category' => $category,
            'show_submitter' => $input['show_submitter'], 'source_published_at' => null, 'created_at' => $now,
        ]);
    }

    public function ingestWeb(object $source, array $entry): bool
    {
        $url = $this->cleanUrl($entry['url'] ?? null);
        if (!$url) return false;
        $title = trim((string) ($entry['title'] ?? ''));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 180) return false;
        $summary = mb_substr(trim((string) ($entry['summary'] ?? '')), 0, 1000);
        $category = $entry['category'] ?? 'notice';
        if (!isset(self::CATEGORIES[$category])) return false;
        $urlHash = $this->urlHash($url);
        $contentHash = hash('sha256', $title."\n".($entry['content_hash'] ?? $summary)."\n".($entry['published_at'] ?? ''));
        $fingerprint = hash('sha256', $urlHash.$contentHash);
        $suggested = $this->db->table('campus_aggregation_candidates')->where('url_hash', $urlHash)->whereNotNull('item_id')->orderByDesc('id')->value('item_id');
        return $this->db->table('campus_aggregation_candidates')->insertOrIgnore([
            'source_id' => $source->id, 'submitter_id' => null, 'item_id' => $suggested,
            'origin' => 'web', 'visibility' => $source->visibility, 'trust' => $source->trust,
            'status' => 'pending', 'fingerprint' => $fingerprint, 'url_hash' => $urlHash, 'content_hash' => $contentHash,
            'source_name' => $source->name, 'source_url' => $url, 'title' => $title, 'summary' => $summary,
            'category' => $category, 'show_submitter' => false,
            'source_published_at' => $entry['published_at'] ?? null, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]) > 0;
    }

    public function visibleItems(User $actor, int $page): array
    {
        $page = max(1, min(10000, $page));
        $query = $this->db->table('campus_aggregation_items');
        if (!AccountAccess::isConfirmed($actor)) $query->where('visibility', 'public');
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->offset(($page - 1) * 20)->limit(20)->get();
        return ['data' => $rows->map(fn ($item) => $this->serializeItem($actor, $item))->all(), 'meta' => ['total' => $total, 'page' => $page, 'page_size' => 20]];
    }

    public function serializeItem(User $actor, object $item): array
    {
        if (!AccountAccess::isConfirmed($actor) && $item->visibility !== 'public') throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
        $sources = $this->db->table('campus_aggregation_candidates')->where('item_id', $item->id)->where('status', 'published');
        if (!AccountAccess::isConfirmed($actor)) $sources->where('visibility', 'public');
        $rows = $sources->orderBy('id')->get(['source_name', 'source_url', 'url_hash', 'trust', 'origin', 'show_submitter', 'submitter_id']);
        $sources = [];
        foreach ($rows as $row) {
            $key = $row->url_hash ?: 'manual:'.$row->source_name.':'.$row->submitter_id;
            $data = [
                'name' => $row->source_name,
                'url' => $item->verification === 'verified' ? $row->source_url : null,
                'trust' => $row->trust,
                'origin' => $row->origin,
                'submitter' => $row->show_submitter && $row->submitter_id ? User::query()->find($row->submitter_id)?->display_name : null,
            ];
            if (!isset($sources[$key]) || $row->trust === 'official') $sources[$key] = $data;
        }
        return ['id' => $item->id, 'title' => $item->title, 'summary' => $item->summary, 'category' => $item->category,
            'verification' => $item->verification, 'visibility' => $item->visibility, 'source_published_at' => $item->source_published_at,
            'created_at' => $item->created_at, 'updated_at' => $item->updated_at, 'sources' => array_values($sources)];
    }

    public function review(User $actor, int $candidateId, array $input): array
    {
        $actor->assertAdmin();
        $action = $input['action'] ?? '';
        if (!in_array($action, ['publish', 'merge', 'update', 'reject'], true)) throw new ValidationException(['action' => '审核动作无效']);
        $reason = $this->text($input, 'reason', $action === 'reject' ? 5 : 0, 500);
        $verified = $input['verified'] ?? false;
        if (!is_bool($verified)) throw new ValidationException(['verified' => '核实状态无效']);
        return $this->db->transaction(function () use ($actor, $candidateId, $action, $reason, $verified, $input) {
            $candidate = $this->db->table('campus_aggregation_candidates')->where('id', $candidateId)->lockForUpdate()->first();
            if (!$candidate || $candidate->status !== 'pending') throw new ValidationException(['candidate' => '候选信息不存在或已审核']);
            $now = gmdate('Y-m-d H:i:s');
            $itemId = null;
            if ($action === 'publish') {
                $itemId = $this->db->table('campus_aggregation_items')->insertGetId([
                    'title' => $candidate->title, 'summary' => $candidate->summary, 'category' => $candidate->category,
                    'verification' => $verified ? 'verified' : 'unverified', 'visibility' => $candidate->visibility,
                    'submitter_id' => $candidate->submitter_id, 'show_submitter' => $candidate->show_submitter,
                    'source_published_at' => $candidate->source_published_at, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } elseif (in_array($action, ['merge', 'update'], true)) {
                $itemId = (int) ($input['item_id'] ?? $candidate->item_id ?? 0);
                $item = $this->db->table('campus_aggregation_items')->where('id', $itemId)->lockForUpdate()->first();
                if (!$item) throw new ValidationException(['item_id' => '目标公开条目不存在']);
                if ($action === 'update') {
                    if ((int) $candidate->item_id !== $itemId) throw new ValidationException(['item_id' => '只能更新该来源已经关联的条目']);
                    $this->db->table('campus_aggregation_items')->where('id', $itemId)->update([
                        'title' => $candidate->title, 'summary' => $candidate->summary, 'category' => $candidate->category,
                        'source_published_at' => $candidate->source_published_at,
                        'verification' => $verified ? 'verified' : 'unverified',
                        'visibility' => $item->visibility === 'members' ? 'members' : $candidate->visibility,
                        'updated_at' => $now,
                    ]);
                }
            }
            $this->db->table('campus_aggregation_candidates')->where('id', $candidateId)->update([
                'status' => $action === 'reject' ? 'rejected' : 'published', 'item_id' => $itemId,
                'reviewer_id' => $actor->id, 'review_reason' => $reason, 'reviewed_at' => $now,
            ]);
            $this->db->table('campus_aggregation_events')->insert([
                'candidate_id' => $candidateId, 'item_id' => $itemId, 'actor_id' => $actor->id,
                'action' => $action, 'reason' => $reason, 'created_at' => $now,
            ]);
            return ['candidate_id' => $candidateId, 'item_id' => $itemId, 'status' => $action === 'reject' ? 'rejected' : 'published'];
        });
    }
}
