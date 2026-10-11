<?php
namespace UCASSer\Campus;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;
use Flarum\Tags\Tag;
use Flarum\Discussion\Discussion;
class SectionPolicy extends AbstractPolicy
{
    public function __construct(private Sections $sections) {}
    public function can(User $actor, string $ability, Tag|Discussion $model): ?string
    {
        $ids = $model instanceof Tag ? [$model->id] : $model->tags->pluck('id')->all();
        foreach ($this->sections->rows() as $s) {
            if (in_array($s->tag_id, $ids) && !$this->sections->allowed($actor, $s)) return $this->forceDeny();
        }
        return null;
    }
}
