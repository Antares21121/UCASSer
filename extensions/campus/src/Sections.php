<?php
namespace UCASSer\Campus;

use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

class Sections
{
    public const DEFAULTS = [
        'news' => ['校园资讯与活动', '校园活动、公共通知与机会'],
        'careers' => ['实习、就业与竞赛', '实习、就业、竞赛与项目招募'],
        'academic' => ['讲座、学术与志愿服务', '讲座、学术活动与志愿参与'],
        'life' => ['校园生活与互助', '生活经验、需求与互助'],
        'market' => ['二手交易与出行信息', '二手物品、拼车与出行'],
        'resources' => ['共享资料库', '资料来源、版权说明与有效链接'],
        'courses' => ['课程评价与学习经验', '课程体验、学习负担与备考经验'],
        'questions' => ['校园问答', '提问、回答与公共知识积累'],
        'chat' => ['闲聊与公共讨论', '友善交流与公共议题'],
        'governance' => ['社区公告与治理', '社区公约、规则与治理公告'],
    ];
    public function __construct(public ConnectionInterface $db) {}
    public function installDefaults(): void
    {
        $this->db->transaction(function () {
            foreach (self::DEFAULTS as $key => [$name, $description]) {
                if ($this->db->table('campus_sections')->where('key', $key)->exists()) continue;
                $tag = Tag::query()->where('slug', 'campus-'.$key)->first();
                if (!$tag) {
                    $tag = Tag::build($name, 'campus-'.$key, $description, '#176d72', 'fas fa-graduation-cap', false);
                    $tag->is_primary = true;
                    $tag->save();
                }
                $this->db->table('campus_sections')->insert(['key' => $key, 'tag_id' => $tag->id]);
            }
        });
    }
    public function rows(): array
    {
        return $this->db->table('campus_sections')->join('tags', 'tags.id', '=', 'campus_sections.tag_id')
            ->select('campus_sections.*', 'tags.name', 'tags.slug', 'tags.description', 'tags.position')
            ->orderBy('tags.position')->orderBy('campus_sections.id')->get()->all();
    }
    public function allowed(User $actor, object $section): bool
    {
        if ($actor->isAdmin()) return true;
        if (!$actor->isGuest() && $this->db->getSchemaBuilder()->hasTable('campus_moderators') && $this->db->table('campus_moderators')->where('user_id',$actor->id)->where('section_id',$section->id)->exists()) return true;
        if (!$section->is_open) return false;
        return match ($section->visibility) {
            'public' => true,
            'members' => !$actor->isGuest(),
            'verified' => $actor->hasPermission('campus.verified'),
            default => false,
        };
    }
    public function visible(User $actor): array
    {
        $tagIds = Tag::query()->whereVisibleTo($actor)->pluck('id')->all();
        return array_values(array_filter($this->rows(), fn ($s) => $this->allowed($actor, $s) && in_array($s->tag_id, $tagIds)));
    }
    public function byId(int $id): object
    {
        $row = $this->db->table('campus_sections')->where('id', $id)->first();
        if (!$row) throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
        return $row;
    }
}
