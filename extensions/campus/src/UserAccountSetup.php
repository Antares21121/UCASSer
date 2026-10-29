<?php
namespace UCASSer\Campus;

use Flarum\User\Event\Deleted;
use Flarum\User\Event\Registered;
use Illuminate\Database\ConnectionInterface;

/**
 * New accounts get a permanent suffix and the experience baseline
 * (0 XP / Lv.1). Deleted accounts keep their suffix reserved so it is never
 * reassigned; their stats row is removed.
 */
class UserAccountSetup
{
    public function __construct(private ConnectionInterface $db, private UserTags $tags) {}

    public function handle(Registered|Deleted $event): void
    {
        $user = $event->user;
        if ($event instanceof Registered) {
            if ($this->db->getSchemaBuilder()->hasTable('campus_user_tags')) $this->tags->allocate((int) $user->id);
            if ($this->db->getSchemaBuilder()->hasTable('campus_user_stats')) {
                $this->db->table('campus_user_stats')->insertOrIgnore([
                    'user_id' => $user->id, 'total_xp' => 0, 'level' => 1, 'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            return;
        }
        if ($this->db->getSchemaBuilder()->hasTable('campus_user_tags')) {
            $this->db->table('campus_user_tags')->where('user_id', $user->id)->update(['user_id' => null, 'reserved' => true]);
        }
        if ($this->db->getSchemaBuilder()->hasTable('campus_user_stats')) {
            $this->db->table('campus_user_stats')->where('user_id', $user->id)->delete();
        }
    }
}
