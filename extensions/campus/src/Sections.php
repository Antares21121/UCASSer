<?php
namespace UCASSer\Campus;

use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

class Sections
{
    public const ICONS = ['careers'=>'briefcase','life'=>'hands-helping','secondhand'=>'box-open','travel'=>'route','resources'=>'book-open','questions'=>'comment-dots','archive'=>'archive'];
    /** Key => [name, description, icon, is_open, visibility]. Retired boards are absent: setup must never rebuild them. */
    public const DEFAULTS = [
        'careers' => ['实习、就业与竞赛', '实习、就业、竞赛与项目招募', 'briefcase', true, 'public'],
        'life' => ['校园生活与互助', '生活经验、需求与互助', 'hands-helping', true, 'public'],
        'secondhand' => ['二手交易', '二手物品转让与求购', 'box-open', true, 'public'],
        'travel' => ['出行信息', '拼车、同行与出行安排', 'route', true, 'public'],
        'resources' => ['共享资料库', '资料来源、版权说明与有效链接', 'book-open', true, 'public'],
        'questions' => ['校园问答', '提问、回答与公共知识积累', 'comment-dots', true, 'public'],
        'archive' => ['课程评价归档', '课程评价讨论的内部归档，不对外展示', 'archive', false, 'public'],
    ];
    public const RETIRED = BoardMigrator::RETIRED;
    public function __construct(public ConnectionInterface $db) {}
    public function installDefaults(): void
    {
        $this->db->transaction(function () {
            foreach (self::DEFAULTS as $key => [$name, $description, $icon, $isOpen, $visibility]) {
                if ($this->db->table('campus_sections')->where('key', $key)->exists()) continue;
                $tag = Tag::query()->where('slug', BoardMigrator::TARGETS[$key])->first();
                if (!$tag) {
                    $tag = Tag::build($name, BoardMigrator::TARGETS[$key], $description, '#A71937', 'fas fa-'.$icon, !$isOpen);
                    $tag->is_primary = true;
                    $tag->save();
                }
                $this->db->table('campus_sections')->insert(['key' => $key, 'tag_id' => $tag->id, 'is_open' => $isOpen, 'visibility' => $visibility]);
            }
            // Retired sections stay closed even if someone reopens them in the admin panel.
            $this->db->table('campus_sections')->whereIn('key', self::RETIRED)->update(['is_open' => false]);
            // And their tags stay out of public tag listings.
            Tag::query()->whereIn('slug', array_map(fn ($key) => 'campus-'.$key, self::RETIRED))->orWhere('slug', 'general')->update(['is_hidden' => true]);
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
        if (AccountAccess::isConfirmed($actor) && $this->db->getSchemaBuilder()->hasTable('campus_moderators') && $this->db->table('campus_moderators')->where('user_id',$actor->id)->where('section_id',$section->id)->exists()) return true;
        if (!$section->is_open && $section->key !== 'archive') return false;
        return match ($section->visibility) {
            'public' => true,
            'members' => AccountAccess::isConfirmed($actor),
            'verified' => $actor->hasPermission('campus.verified'),
            default => false,
        };
    }
    public function visible(User $actor): array
    {
        $tagIds = Tag::query()->whereVisibleTo($actor)->pluck('id')->all();
        return array_values(array_filter($this->rows(), fn ($s) => $s->key !== 'archive' && !in_array($s->key, self::RETIRED, true) && $this->allowed($actor, $s) && in_array($s->tag_id, $tagIds)));
    }
    public function byId(int $id): object
    {
        $row = $this->db->table('campus_sections')->where('id', $id)->first();
        if (!$row) throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
        return $row;
    }
    /** The internal archive section used to categorize review discussions. */
    public function archive(): ?object
    {
        return $this->db->table('campus_sections')->where('key', 'archive')->first();
    }
    public function byKey(string $key): ?object
    {
        return $this->db->table('campus_sections')->where('key', $key)->first();
    }
}
