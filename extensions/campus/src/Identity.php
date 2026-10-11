<?php
namespace UCASSer\Campus;
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\User\User;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
class Identity
{
    public function __construct(public ConnectionInterface $db, private SettingsRepositoryInterface $settings) {}
    public function setup(): void
    {
        foreach (['verified' => ['校内验证成员', 'campus.verified'], 'volunteer' => ['分区志愿者', 'campus.volunteer']] as $key => [$name, $permission]) {
            $id = (int)$this->settings->get('campus.group.'.$key);
            if (!$id || !Group::query()->find($id)) {
                $group = new Group; $group->name_singular = $name; $group->name_plural = $name;
                $group->color = '#176d72'; $group->icon = 'fas fa-graduation-cap'; $group->is_hidden = $key === 'volunteer'; $group->save();
                $id = $group->id; $this->settings->set('campus.group.'.$key, $id);
            }
            if ($id <= 4) throw new \RuntimeException('Campus roles must not reuse privileged built-in groups');
            $this->db->table('group_permission')->insertOrIgnore(['group_id' => $id, 'permission' => $permission]);
        }
    }
    public function group(string $key): int { return (int)$this->settings->get('campus.group.'.$key); }
    public function moderator(User $actor, int $section): bool
    {
        return $actor->isAdmin() || (!$actor->isGuest() && $this->db->table('campus_moderators')->where('user_id', $actor->id)->where('section_id', $section)->exists());
    }
    public function audit(User $actor, string $action, string $target, ?string $reason = null): void
    {
        $this->db->table('campus_audit')->insert(['actor_id' => $actor->id, 'action' => $action, 'target' => $target, 'reason' => $reason, 'created_at' => date('Y-m-d H:i:s')]);
    }
}
