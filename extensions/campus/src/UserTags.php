<?php
namespace UCASSer\Campus;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Permanent, unique, public numeric suffixes that disambiguate equal display
 * names. Suffixes are allocated once per account and never reused, even after
 * the account is deleted. The database unique index plus bounded retries make
 * concurrent allocation safe; the four-digit minimum grows automatically.
 */
class UserTags
{
    public const MIN_SUFFIX = 1000;

    public function __construct(private ConnectionInterface $db) {}

    public function enabled(): bool
    {
        return $this->db->getSchemaBuilder()->hasTable('campus_user_tags');
    }

    public function forUser(int $userId): ?int
    {
        if (!$this->enabled()) return null;
        $row = $this->db->table('campus_user_tags')->where('user_id', $userId)->first();
        return $row ? (int) $row->suffix : null;
    }

    public function displayForUser(?User $user): ?string
    {
        if (!$user) return null;
        $suffix = $this->forUser((int) $user->id);
        return $suffix === null ? null : '#'.$suffix;
    }

    public function bySuffix(int $suffix): ?User
    {
        if (!$this->enabled()) return null;
        $row = $this->db->table('campus_user_tags')->where('suffix', $suffix)->first();
        return $row && $row->user_id ? User::query()->find($row->user_id) : null;
    }

    /** Allocate (or fetch) the permanent suffix for a user. */
    public function allocate(int $userId): int
    {
        $existing = $this->forUser($userId);
        if ($existing !== null) return $existing;
        $next = max(self::MIN_SUFFIX, (int) $this->db->table('campus_user_tags')->max('suffix') + 1);
        for ($attempt = 0; $attempt < 40; $attempt++) {
            try {
                $this->db->table('campus_user_tags')->insert(['user_id' => $userId, 'suffix' => $next, 'created_at' => gmdate('Y-m-d H:i:s')]);
                return $next;
            } catch (\Illuminate\Database\QueryException $error) {
                if (!str_contains((string) $error->getMessage(), 'Duplicate') && !str_contains((string) $error->getMessage(), 'duplicate')) throw $error;
                $next++;
            }
        }
        throw new \RuntimeException('Unable to allocate a unique campus suffix');
    }

    /** Backfill every existing account idempotently. */
    public function backfill(): int
    {
        if (!$this->enabled()) return 0;
        $assigned = 0;
        foreach (User::query()->orderBy('id')->pluck('id')->all() as $userId) {
            if ($this->forUser($userId) !== null) continue;
            $this->allocate($userId);
            $assigned++;
        }
        return $assigned;
    }
}
