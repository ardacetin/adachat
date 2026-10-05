<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Services\CurrentLimits;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\LastSuperAdmin;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Mail\UserInvitation;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

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
        private readonly InstitutionSettings $institution,
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
     * Adds accounts for e-mail addresses that do not have one yet. The
     * person signs in with the institution's identity provider; the account
     * is linked by the (verified) address on the first sign-in. With
     * $sendEmail, each new account gets an e-mail saying so; a failed
     * e-mail does not undo the account.
     *
     * @param  list<array{email: string, name: string|null}>  $people
     * @return array{created: list<string>, existing: list<string>, emailed: int, email_failed: int}
     */
    public function invite(array $people, Group $group, UserRole $role, User $actor, bool $sendEmail = false): array
    {
        $created = [];
        $existing = [];
        $emailed = 0;
        $failed = 0;

        foreach ($people as $person) {
            $email = mb_strtolower(trim($person['email']));

            if (User::query()->where('email', $email)->exists()) {
                $existing[] = $email;

                continue;
            }

            $user = new User;
            $user->forceFill([
                'email' => $email,
                'name' => filled($person['name']) ? trim((string) $person['name']) : Str::before($email, '@'),
                'role' => $role,
                'group_id' => $group->id,
                'invited_at' => now(),
                'invited_by' => $actor->id,
            ])->save();

            $sent = $sendEmail && $this->sendInvitation($user, $actor);
            $emailed += $sent ? 1 : 0;
            $failed += $sendEmail && ! $sent ? 1 : 0;

            $this->audit->record('user.invited', $user, [], ['email' => $email, 'role' => $role->value, 'group_id' => $group->id, 'email_sent' => $sent]);
            $created[] = $email;
        }

        return ['created' => $created, 'existing' => $existing, 'emailed' => $emailed, 'email_failed' => $failed];
    }

    /**
     * E-mails the invitation to an account that was added but has not
     * signed in yet: one added before invitation e-mails existed, one whose
     * e-mail failed, or one whose e-mail went missing.
     */
    public function resendInvitation(User $user, User $actor): bool
    {
        if (! $user->invitationPending()) {
            throw new InvalidArgumentException('Only unused invitations can be sent again.');
        }

        $sent = $this->sendInvitation($user, $actor);
        $this->audit->record('user.invitation_sent', $user, [], ['email' => $user->email, 'email_sent' => $sent]);

        return $sent;
    }

    /**
     * In the institution's default language: the person has not chosen one yet.
     */
    private function sendInvitation(User $user, User $actor): bool
    {
        try {
            Mail::to($user->email)
                ->locale($this->institution->default_locale)
                ->send(new UserInvitation($this->institution->name, $actor->name, $user->email));

            return true;
        } catch (Throwable $exception) {
            // The mailer's message names the cause (host, authentication).
            Log::warning('Invitation e-mail failed.', ['user_id' => $user->id, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * Removes an account that was added but never used. Accounts that have
     * signed in are disabled instead (their usage stays on record).
     */
    public function removeInvitation(User $user): void
    {
        if (! $user->invitationPending() || $user->identities()->exists()) {
            throw new InvalidArgumentException('Only unused invitations can be removed.');
        }

        DB::transaction(function () use ($user): void {
            // A budget period may exist if a limit was applied before the first sign-in.
            DB::table('budget_periods')->where('user_id', $user->id)->delete();
            $this->audit->record('user.invitation_removed', $user, ['email' => $user->email], []);
            $user->delete();
        });
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
