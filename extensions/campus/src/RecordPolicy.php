<?php
namespace UCASSer\Campus;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;
class RecordPolicy extends AbstractPolicy
{
    public function __construct(private ConnectionInterface $db) {}
    public function can(User $a,string $ability,Discussion|Post $model): ?string {
        $d=$model instanceof Post?$model->discussion:$model;
        if ($d->hidden_at && $d->hidden_user_id!==$a->id && !$a->isAdmin()) return null;
        $own=$d->user_id===$a->id && !$a->isGuest();
        $relevant=$model instanceof Post?($ability==='edit' && $model->number===1 && !$model->hidden_at):in_array($ability,['rename','tag'],true);
        if ($own && $relevant && $this->db->table('campus_records')->where('discussion_id',$d->id)->exists() && $a->can('reply',$d)) return $this->allow();
        return null;
    }
}
