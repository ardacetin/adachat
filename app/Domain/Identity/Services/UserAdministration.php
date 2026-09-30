<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Services\CurrentLimits;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\LastSuperAdmin;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Changes administrators make to a user account (authorization is the
 * caller's job, see UserPolicy). Every change is audited; budget-relevant
 * changes can be applied to the current month.
 */
final class UserAdministration
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrentLimits $limits,
    ) {}

    public function changeGroup(User $user, Group $group, bool $applyToCurrentPeriod): void
    {
        $old = $user->group_id;

        if ($old === $group->id) {
            return;
        }

        $user->group()->associate($group)->save();
        $this->audit->record('user.group_changed', $user, ['group_id' => $old], ['group_id' => $group->id]);

        if ($applyToCurrentPeriod) {
            $this->limits->apply(User::query()->whereKey($user->id));
        }
    }

    /**
     * @param  Usd|null  $limit  null: the group policy applies again
     */
    public function setBudgetOverride(User $user, ?Usd $limit, bool $applyToCurrentPeriod): void
    {
        $old = $user->monthly_limit_override_usd;

        if ($old?->toString() === $limit?->toString()) {
            return;
        }

        $user->monthly_limit_override_usd = $limit;
        $user->save();

        $this->audit->record(
            'user.budget_override_changed',
            $user,
            ['monthly_limit_override_usd' => $old?->toString()],
            ['monthly_limit_override_usd' => $limit?->toString()],
        );

        if ($applyToCurrentPeriod) {
            $this->limits->apply(User::query()->whereKey($user->id));
        }
    }

    /**
     * @throws LastSuperAdmin
     */
    public function setStatus(User $user, UserStatus $status): void
    {
        if ($user->status === $status) {
            return;
        }

        if ($status === UserStatus::Disabled) {
            $this->guardLastSuperAdmin($user);
        }

        $old = $user->status;

        DB::transaction(function () use ($user, $status): void {
            $user->forceFill([
                'status' => $status,
                'disabled_at' => $status === UserStatus::Disabled ? now() : null,
            ])->save();

            if ($status === UserStatus::Disabled) {
                $this->endSessions($user);
            }
        });

        $this->audit->record('user.status_changed', $user, ['status' => $old->value], ['status' => $status->value]);
    }

    /**
     * @throws LastSuperAdmin
     */
    public function changeRole(User $user, UserRole $role): void
    {
        if ($user->role === $role) {
            return;
        }

        $this->guardLastSuperAdmin($user);

        $old = $user->role;
        $user->role = $role;
        $user->save();

        $this->audit->record('user.role_changed', $user, ['role' => $old->value], ['role' => $role->value]);
    }

    /**
     * Refuse to remove the last active super administrator (by demotion or
     * by disabling): nobody could manage the system any more.
     */
    private function guardLastSuperAdmin(User $user): void
    {
        if ($user->role !== UserRole::SuperAdmin || $user->status !== UserStatus::Active) {
            return;
        }

        $others = User::query()
            ->where('role', UserRole::SuperAdmin)
            ->where('status', UserStatus::Active)
            ->whereKeyNot($user->id)
            ->exists();

        if (! $others) {
            throw new LastSuperAdmin('The last active super administrator cannot be demoted or disabled.');
        }
    }

    /**
     * Sign the user out everywhere: stored sessions are deleted and the
     * "remember me" token is rotated. (With other session drivers the
     * EnsureUserIsActive middleware signs them out on their next request.)
     */
    private function endSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }
}
