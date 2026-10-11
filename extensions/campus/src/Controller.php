<?php
namespace UCASSer\Campus;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Flarum\Foundation\ValidationException;
class Controller implements RequestHandlerInterface
{
    public function __construct(protected Sections $sections, protected SettingsRepositoryInterface $settings, protected Records $records) {}
    public function handle(ServerRequestInterface $r): ResponseInterface
    {
        $actor = RequestUtil::getActor($r);
        $route = $r->getAttribute('routeName');
        if ($route === 'campus.home') {
            $base = Discussion::query()->whereVisibleTo($actor)->with(['tags', 'user']);
            $serialize = fn ($items) => $items->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'createdAt' => $d->created_at->toIso8601String(), 'comments' => $d->comment_count, 'author' => $d->user?->display_name ?? '已删除账号', 'tags' => $d->tags->pluck('name')->all()])->all();
            return new JsonResponse(['data' => [
                'title' => $this->settings->get('forum_title'),
                'description' => $this->settings->get('campus.description', '共同维护校园信息与公共知识'),
                'sections' => $this->sections->visible($actor),
                'latest' => $serialize((clone $base)->latest('created_at')->limit(8)->get()),
                'popular' => $serialize((clone $base)->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))->orderByDesc('comment_count')->latest('created_at')->limit(5)->get()),
                'questions' => $serialize((clone $base)->whereHas('tags', fn ($q) => $q->where('slug', 'campus-questions'))->latest('created_at')->limit(5)->get()),
                'information' => $this->records->query($actor)->where('type','information')->orderByDesc('id')->limit(5)->get()->map(fn($row)=>$this->records->serialize($actor,$row))->all(),
                'upcoming' => $this->records->query($actor)->where('type','information')->where('status','active')->whereRaw('('.Records::expiry().' IS NULL OR '.Records::expiry().' >= ?)',[gmdate('Y-m-d H:i:s')])->where(fn($q)=>$q->where('deadline','>=',gmdate('Y-m-d H:i:s'))->orWhere('starts_at','>=',gmdate('Y-m-d H:i:s')))->orderByRaw('COALESCE(deadline,starts_at) ASC')->limit(5)->get()->map(fn($row)=>$this->records->serialize($actor,$row))->all(),
            ]]);
        }
        if ($route === 'campus.rules') return new JsonResponse(['data' => [
            'rules' => $this->sections->db->table('campus_governance')->where('type','rule')->where('effective_at','<=',gmdate('Y-m-d H:i:s'))->orderByDesc('id')->value('body') ?? $this->settings->get('campus.rules', '公益共享、友善交流。请提供来源，不发布隐私、侵权资料或垃圾广告。通过帖子举报入口反馈问题。'),
            'governance' => $this->settings->get('campus.governance', '分区志愿者协助维护，管理员处理跨分区问题。举报材料只向获授权的处理人员开放。'),
        ]]);
        $actor->assertAdmin();
        if ($r->getMethod() === 'GET') return new JsonResponse(['data' => $this->sections->rows()]);
        $s = $this->sections->byId((int)($r->getQueryParams()['id'] ?? 0));
        $data = $r->getParsedBody() ?? [];
        if (!is_string($data['name'] ?? null) || mb_strlen(trim($data['name'])) < 2 || mb_strlen($data['name']) > 100
            || !is_string($data['description'] ?? null) || mb_strlen($data['description']) > 1000
            || !in_array($data['visibility'] ?? null, ['public', 'members', 'verified'], true)
            || !is_bool($data['is_open'] ?? null) || !is_int($data['position'] ?? null) || $data['position'] < 0) {
            throw new ValidationException(['section' => '请检查名称、简介、顺序、开放状态与可见范围']);
        }
        $this->sections->db->transaction(function () use ($data, $s) {
            Tag::query()->where('id', $s->tag_id)->update(['name' => trim($data['name']), 'description' => $data['description'], 'position' => $data['position']]);
            $this->sections->db->table('campus_sections')->where('id', $s->id)->update(['is_open' => $data['is_open'], 'visibility' => $data['visibility']]);
        });
        return new JsonResponse(['data' => $this->sections->rows()]);
    }
}
