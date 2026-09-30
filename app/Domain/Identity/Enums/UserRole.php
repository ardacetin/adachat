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

    /**
     * Whether the role may manage system-level configuration (providers,
     * credentials, models, budget policies, authentication, roles).
     */
    public function canManageSystem(): bool
    {
        return $this === self::SuperAdmin;
    }
}
