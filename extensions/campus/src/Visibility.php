<?php
namespace UCASSer\Campus;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
class Visibility
{
    public function __construct(private Sections $sections) {}
    public function __invoke(User $actor, Builder $query): void
    {
        $denied = array_map(fn ($s) => $s->tag_id, array_filter($this->sections->rows(), fn ($s) => !$this->sections->allowed($actor, $s)));
        if ($denied) $query->whereDoesntHave('tags', fn ($q) => $q->whereIn('tags.id', $denied));
    }
}
