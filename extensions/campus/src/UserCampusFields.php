<?php
namespace UCASSer\Campus;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Exposes the permanent display suffix and the experience/level baseline on
 * the users resource. Values are strings so empty defaults never break the
 * JSON:API type, and they are read-only: no write path mutates them here.
 */
class UserCampusFields
{
    public function __construct(private ConnectionInterface $db) {}

    public function __invoke(): array
    {
        $suffix = fn (User $user): string => (string) ($user->id ? ($this->db->getSchemaBuilder()->hasTable('campus_user_tags') ? $this->db->table('campus_user_tags')->where('user_id', $user->id)->value('suffix') : null) : null);
        $stat = fn (User $user, string $key): string => (string) ($user->id && $this->db->getSchemaBuilder()->hasTable('campus_user_stats') ? $this->db->table('campus_user_stats')->where('user_id', $user->id)->value($key) : ($key === 'level' ? '1' : '0'));
        return [
            Schema\Str::make('campusSuffix')->get(fn (User $user) => $suffix($user) ?? ''),
            Schema\Str::make('campusLevel')->get(fn (User $user) => $stat($user, 'level') ?? '1'),
            Schema\Str::make('campusXp')->get(fn (User $user) => $stat($user, 'total_xp') ?? '0'),
        ];
    }
}
