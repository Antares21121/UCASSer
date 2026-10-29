<?php
namespace UCASSer\Campus;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

class OperatorAccess
{
    public function __construct(private ConnectionInterface $db) {}

    public function allowed(User $actor): bool
    {
        return !$actor->isGuest() && $this->db->table('campus_operators')->where('user_id', $actor->id)->exists();
    }

    public function assert(User $actor): void
    {
        if (!$this->allowed($actor)) throw new \Flarum\User\Exception\PermissionDeniedException();
    }
}
