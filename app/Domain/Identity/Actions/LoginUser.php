<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Budget\Services\CurrentLimits;
use App\Domain\Identity\Data\ExternalIdentity;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Exceptions\IdentityRejected;
use App\Domain\Identity\Exceptions\RejectionReason;
use App\Domain\Identity\Providers\SamlIdentityProvider;
use App\Domain\Identity\Services\AllowedDomainPolicy;
use App\Domain\Identity\Services\GroupMapping;
use App\Models\Group;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Protocol-independent sign-in: domain policy → identity lookup/linking →
 * just-in-time provisioning → group mapping → active check. Session handling stays in the
 * HTTP layer. Addresses an administrator added (invited) may sign in
 * whatever their domain and whether or not accounts are created
 * automatically.
 */
final class LoginUser
{
    public function __construct(
        private readonly AllowedDomainPolicy $domainPolicy,
        private readonly bool $autoProvision,
        private readonly GroupMapping $groups,
        private readonly AuditLogger $audit,
        private readonly CurrentLimits $limits,
    ) {}

    /**
     * @throws IdentityRejected
     */
    public function handle(ExternalIdentity $identity, bool $requireHostedDomain): User
    {
        $invited = User::query()->where('email', $identity->email)->whereNotNull('invited_at')->exists();
        $this->domainPolicy->assertAllowed($identity, $requireHostedDomain, $invited);

        try {
            $user = $this->persist($identity);
        } catch (UniqueConstraintViolationException $exception) {
            // The e-mail already belongs to another account; an admin must resolve it.
            throw new IdentityRejected(RejectionReason::AccountConflict, $exception);
        }

        if (! $user->isActive()) {
            throw new IdentityRejected(RejectionReason::AccountDisabled);
        }

        return $user;
    }

    private function persist(ExternalIdentity $identity): User
    {
        return DB::transaction(function () use ($identity): User {
            $record = UserIdentity::query()
                ->where('provider', $identity->provider)
                ->where('subject', $identity->subject)
                ->lockForUpdate()
                ->first();

            // Stored under its previous subject (e.g. the e-mail address
            // before a stable ID was configured): move it to the new one.
            if ($record === null && $identity->previousSubject !== null) {
                $record = UserIdentity::query()
                    ->where('provider', $identity->provider)
                    ->where('subject', $identity->previousSubject)
                    ->lockForUpdate()
                    ->first();
            }

            $user = $record->user
                ?? $this->findUserByEmail($identity)
                ?? $this->provision($identity);

            // A disabled account is not linked to a new sign-in method: it
            // would pass to that identity the day the account is enabled.
            if ($record === null && $user->exists && ! $user->isActive()) {
                throw new IdentityRejected(RejectionReason::AccountDisabled);
            }

            $user->forceFill([
                'name' => $identity->name,
                'email' => $identity->email,
                'avatar_url' => $identity->avatarUrl,
            ]);

            if ($user->isActive()) {
                $user->last_login_at = now();
            }

            $group = $this->groups->resolve($user, $identity);
            $previousGroup = $user->exists ? $user->group_id : null;

            if ($group !== null) {
                $user->group_id = $group->id;
            }

            $user->save();

            if ($previousGroup !== null && $previousGroup !== $user->group_id) {
                $this->audit->record('user.group_changed', $user, ['group_id' => $previousGroup], ['group_id' => $user->group_id, 'source' => 'identity_provider']);
                $this->limits->apply(User::query()->whereKey($user->id));
            }

            $record ??= new UserIdentity;
            $record->forceFill([
                'user_id' => $user->id,
                'provider' => $identity->provider,
                'subject' => $identity->subject,
                'email' => $identity->email,
                // The values the provider sent, to set up group mapping with.
                'last_claims' => $identity->groups === null ? $identity->safeClaims : [
                    ...$identity->safeClaims,
                    'groups' => Str::limit(implode(', ', $identity->groups), 1000),
                ],
                'last_login_at' => now(),
            ])->save();

            return $user;
        });
    }

    /**
     * Link by the verified e-mail address only to an account that no stable
     * identifier claims yet: one added by an administrator (or ada:user:promote)
     * that never signed in, or one known only by its SAML e-mail address
     * (no SAML_ATTRIBUTE_SUBJECT), which says nothing more than the address.
     * An account bound to a stable subject at any provider is never handed to
     * another subject because the address matches: a new holder of a reused
     * address gets account_conflict.
     */
    private function findUserByEmail(ExternalIdentity $identity): ?User
    {
        return User::query()
            ->where('email', $identity->email)
            ->whereDoesntHave('identities', fn ($query) => $query->where(fn ($claims) => $claims
                ->where('provider', $identity->provider)
                ->orWhere('provider', '!=', SamlIdentityProvider::KEY)
                ->orWhereColumn('user_identities.subject', '!=', 'users.email')))
            ->lockForUpdate()
            ->first();
    }

    private function provision(ExternalIdentity $identity): User
    {
        if (User::query()->where('email', $identity->email)->exists()) {
            // Same e-mail, but linked to a different account at this provider.
            throw new IdentityRejected(RejectionReason::AccountConflict);
        }

        if (! $this->autoProvision) {
            throw new IdentityRejected(RejectionReason::NotProvisioned);
        }

        $user = new User;
        $user->forceFill([
            'name' => $identity->name,
            'email' => $identity->email,
            'role' => UserRole::User,
            'group_id' => Group::default()->id,
        ]);

        return $user;
    }
}
