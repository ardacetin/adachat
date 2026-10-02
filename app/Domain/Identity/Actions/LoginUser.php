<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\ExternalIdentity;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Exceptions\IdentityRejected;
use App\Domain\Identity\Exceptions\RejectionReason;
use App\Domain\Identity\Services\AllowedDomainPolicy;
use App\Models\Group;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Protocol-independent sign-in: domain policy → identity lookup/linking →
 * just-in-time provisioning → active check. Session handling stays in the
 * HTTP layer. Addresses an administrator added (invited) may sign in
 * whatever their domain and whether or not accounts are created
 * automatically.
 */
final class LoginUser
{
    public function __construct(
        private readonly AllowedDomainPolicy $domainPolicy,
        private readonly bool $autoProvision,
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

            $user = $record->user
                ?? $this->findUserByEmail($identity)
                ?? $this->provision($identity);

            $user->forceFill([
                'name' => $identity->name,
                'email' => $identity->email,
                'avatar_url' => $identity->avatarUrl,
            ]);

            if ($user->isActive()) {
                $user->last_login_at = now();
            }

            $user->save();

            $record ??= new UserIdentity;
            $record->forceFill([
                'user_id' => $user->id,
                'provider' => $identity->provider,
                'subject' => $identity->subject,
                'email' => $identity->email,
                'last_claims' => $identity->safeClaims,
                'last_login_at' => now(),
            ])->save();

            return $user;
        });
    }

    /**
     * Link to a pre-created user (e.g. by ada:user:promote) only when no
     * identity of this provider is attached yet and the verified e-mail
     * matches exactly.
     */
    private function findUserByEmail(ExternalIdentity $identity): ?User
    {
        return User::query()
            ->where('email', $identity->email)
            ->whereDoesntHave('identities', fn ($query) => $query->where('provider', $identity->provider))
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
