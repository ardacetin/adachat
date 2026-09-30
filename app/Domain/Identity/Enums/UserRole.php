<?php

namespace App\Domain\Identity\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case User = 'user';

    /**
     * Whether the role may open the administration area.
     */
    public function canAccessAdmin(): bool
    {
        return $this !== self::User;
    }
}
