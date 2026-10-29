<?php
namespace UCASSer\Campus;

use Flarum\Discussion\Discussion;
use Flarum\Tags\Tag;
use Illuminate\Database\ConnectionInterface;

/**
 * Moves discussions out of retired boards into the current board set.
 *
 * The classifier is deterministic and idempotent: every run replans the same
 * way, applied moves are recorded in campus_board_migrations and never
 * replayed, and every discussion of a retired board is moved somewhere --
 * automatic rules first, then a documented fallback -- so closing a board
 * can never hide or orphan content.
 */
class BoardMigrator
{
    /** Retired board keys; their sections are closed and tags hidden. */
    public const RETIRED = ['general', 'market', 'academic', 'news', 'chat', 'governance', 'courses'];
    /** section key => tag slug for the retained/new boards. */
    public const TARGETS = [
        'secondhand' => 'campus-secondhand',
        'travel' => 'campus-travel',
        'life' => 'campus-life',
        'careers' => 'campus-careers',
        'questions' => 'campus-questions',
        'resources' => 'campus-resources',
        'archive' => 'campus-archive',
    ];
    public const DEFAULT_TARGET = 'life';
    public const MANUAL_TARGET = 'life';
    public const ARCHIVE_TARGET = 'archive';

    private const INFO_RULES = [
        'secondhand' => ['market'],
        'travel' => ['travel', 'buddy'],
        'careers' => ['internship', 'job', 'competition', 'recruit', 'lecture', 'activity', 'volunteer', 'academic'],
        'life' => ['help', 'other'],
    ];

    public function __construct(private ConnectionInterface $db) {}

    public function table(): \Illuminate\Database\Query\Builder
    {
        return $this->db->table('campus_board_migrations');
    }

    /** Tag slug => section key map for the current board set. */
    public function sectionKeyBySlug(string $slug): ?string
    {
        $flip = [];
        foreach (self::TARGETS as $key => $tagSlug) $flip[$tagSlug] = $key;
        return $flip[$slug] ?? ($this->db->table('campus_sections')->join('tags', 'tags.id', '=', 'campus_sections.tag_id')->where('tags.slug', $slug)->value('campus_sections.key'));
    }

    /**
     * Deterministic target section key for one discussion.
     * Returns [sectionKey, note|null]. The last two elements describe an
     * automatic classification; a null note marks a fallback/manual case.
     */
    public function classify(int $discussionId): array
    {
        $record = $this->db->table('campus_records')->where('discussion_id', $discussionId)->first();
        if ($record) {
            $type = $record->type;
            if ($type === 'resource') return ['resources', null];
            if ($type === 'question') return ['questions', null];
            if ($type === 'review') return [self::ARCHIVE_TARGET, null];
            if ($type === 'information' && $record->category) {
                foreach (self::INFO_RULES as $section => $categories) {
                    if (in_array($record->category, $categories, true)) return [$section, null];
                }
                return [self::MANUAL_TARGET, '信息分类 '.$record->category.' 无明确去向，暂放校园生活与互助，待人工复核'];
            }
            return [self::MANUAL_TARGET, '结构化记录缺少可判定分类，暂放校园生活与互助，待人工复核'];
        }
        $title = (string) $this->db->table('discussions')->where('id', $discussionId)->value('title');
        foreach ([
            'travel' => ['拼车', '出行', '顺路', '同行', '车票', '返乡', '返校', '包车'],
            'secondhand' => ['二手', '转卖', '出售', '出闲置', '求购', '转让'],
            'questions' => ['求助', '怎么', '如何', '请问', '求问', '有没有人', '哪里'],
            'careers' => ['实习', '招聘', '竞赛', '志愿', '讲座', '招募', '比赛'],
        ] as $section => $words) {
            foreach ($words as $word) if (mb_strpos($title, $word) !== false) return [$section, null];
        }
        return [self::DEFAULT_TARGET, '无结构化记录且标题无关键词，按默认规则移入校园生活与互助'];
    }

    /** Source tag ids of retired boards plus the legacy "general" tag. */
    public function retiredTagIds(): array
    {
        $slugs = array_merge(array_map(fn ($key) => 'campus-'.$key, self::RETIRED), ['general']);
        return Tag::query()->whereIn('slug', $slugs)->pluck('id')->all();
    }

    /** Preview rows: what would move where, without writing anything. */
    public function plan(): array
    {
        $sections = $this->db->table('campus_sections')->get()->keyBy('key');
        $rows = [];
        $seen = [];
        foreach ($this->retiredTagIds() as $tagId) {
            $ids = $this->db->table('discussion_tag')->where('tag_id', $tagId)->pluck('discussion_id')->all();
            foreach ($ids as $discussionId) {
                if (isset($seen[$discussionId]) || $this->table()->where('discussion_id', $discussionId)->exists()) continue;
                $seen[$discussionId] = true;
                [$sectionKey, $note] = $this->classify($discussionId);
                $section = $sections->get($sectionKey);
                $rows[] = [
                    'discussion_id' => $discussionId,
                    'source_tag_id' => $tagId,
                    'section_key' => $sectionKey,
                    'target_tag_id' => $section->tag_id ?? null,
                    'note' => $note,
                    'status' => $note ? 'manual' : 'auto',
                ];
            }
        }
        return $rows;
    }

    /** Apply planned moves inside one transaction. Returns applied count. */
    public function apply(): int
    {
        $sections = $this->db->table('campus_sections')->get()->keyBy('key');
        $applied = 0;
        $now = gmdate('Y-m-d H:i:s');
        $retiredTagIds = $this->retiredTagIds();
        $this->db->transaction(function () use (&$applied, $sections, $now, $retiredTagIds) {
            foreach ($this->plan() as $row) {
                $discussionId = $row['discussion_id'];
                $sourceTagId = $row['source_tag_id'];
                $targetSection = $sections->get($row['section_key']);
                if (!$targetSection || !$targetSection->tag_id) throw new \RuntimeException('迁移目标板块缺失：'.$row['section_key']);
                $this->db->table('discussion_tag')->where('discussion_id', $discussionId)->whereIn('tag_id', $retiredTagIds)->delete();
                if (!$this->db->table('discussion_tag')->where('discussion_id', $discussionId)->where('tag_id', $targetSection->tag_id)->exists()) {
                    $this->db->table('discussion_tag')->insert(['discussion_id' => $discussionId, 'tag_id' => $targetSection->tag_id]);
                }
                $this->db->table('campus_records')->where('discussion_id', $discussionId)->update(['section_id' => $targetSection->id]);
                $this->table()->insert([
                    'discussion_id' => $discussionId, 'source_tag_id' => $sourceTagId,
                    'target_section_id' => $targetSection->id, 'target_tag_id' => $targetSection->tag_id,
                    'status' => $row['status'], 'note' => $row['note'], 'applied_at' => $now,
                ]);
                $applied++;
            }
        });
        return $applied;
    }

    /** Create the current board tag set (new boards + hidden archive) if missing. */
    public function ensureBoards(): void
    {
        (new Sections($this->db))->installDefaults();
    }

    /** Fill new installs with the current board set (never recreates retired boards). */
    public function installDefaults(): void
    {
        $this->ensureBoards();
    }
}
