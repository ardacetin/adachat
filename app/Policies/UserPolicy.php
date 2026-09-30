<?php

namespace App\Policies;

use App\Domain\Identity\Enums\UserRole;
use App\Models\User;

/**
 * Who may manage whom (docs/authentication.md §4). Administrators manage
 * users' group, status and individual budget; only super administrators
 * change roles, adjust budgets and manage other super administrators.
 * Nobody changes their own role or status.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role->canAccessAdmin();
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->role->canAccessAdmin();
    }

    /** Group and individual budget. */
    public function update(User $actor, User $user): bool
    {
        return $actor->role->canAccessAdmin()
            && ($user->role !== UserRole::SuperAdmin || $actor->role === UserRole::SuperAdmin);
    }

    /** Disable or enable. */
    public function changeStatus(User $actor, User $user): bool
    {
        return $this->update($actor, $user) && $actor->isNot($user);
    }

    public function changeRole(User $actor, User $user): bool
    {
        return $actor->role->canManageSystem() && $actor->isNot($user);
    }

    /** Manual credit or charge on the current period. */
    public function adjustBudget(User $actor, User $user): bool
    {
        return $actor->role->canManageSystem();
    }
}
