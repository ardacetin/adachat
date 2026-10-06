<?php

namespace App\Policies;

use App\Domain\Identity\Enums\UserRole;
use App\Models\User;

/**
 * Who may manage whom (docs/authentication.md §4). Administrators manage
 * users' group, status and individual budget; only super administrators
 * change roles, adjust budgets and manage other super administrators.
 * Nobody changes their own role or status, and administrators do not
 * change their own group or budget.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role->canAccessAdmin();
    }

    /** Add users by e-mail address; roles other than "user" need a super administrator. */
    public function create(User $actor): bool
    {
        return $actor->role->canAccessAdmin();
    }

    /** E-mail the invitation (again) to an account that was added but never used. */
    public function sendInvitation(User $actor, User $user): bool
    {
        return $this->update($actor, $user) && $user->invitationPending() && $user->isActive();
    }

    /** Remove an account that was added but never used. */
    public function removeInvitation(User $actor, User $user): bool
    {
        // A disabled account that tried to sign in is linked already: it stays.
        return $this->update($actor, $user) && $actor->isNot($user) && $user->invitationPending()
            && ! $user->identities()->exists();
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->role->canAccessAdmin();
    }

    /** Group and individual budget; an administrator's own only by a super administrator. */
    public function update(User $actor, User $user): bool
    {
        return $actor->role->canAccessAdmin()
            && ($user->role !== UserRole::SuperAdmin || $actor->role === UserRole::SuperAdmin)
            && ($actor->isNot($user) || $actor->role->canManageSystem());
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
