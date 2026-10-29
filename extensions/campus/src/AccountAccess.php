<?php
namespace UCASSer\Campus;

use Flarum\User\User;

final class AccountAccess
{
    public static function isConfirmed(User $actor): bool
    {
        return !$actor->isGuest() && $actor->is_email_confirmed;
    }

    public static function assertConfirmed(User $actor): void
    {
        $actor->assertRegistered();
        $actor->assertPermission(self::isConfirmed($actor));
    }
}
