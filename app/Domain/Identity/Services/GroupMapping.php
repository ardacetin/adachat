<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Data\ExternalIdentity;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Exceptions\IdentityRejected;
use App\Domain\Identity\Exceptions\RejectionReason;
use App\Models\Group;
use App\Models\User;

/**
 * Picks a user's group from the identity provider's groups at sign-in.
 * Each Ada group lists the provider's values (names or IDs) whose members
 * it takes; among several matches the lowest priority wins, then the
 * oldest group. Values compare case-insensitively.
 */
final class GroupMapping
{
    public const UNMATCHED = ['keep', 'default', 'reject'];

    public function __construct(
        private readonly bool $enabled,
        private readonly string $unmatched,
    ) {}

    /**
     * @param  list<string>  $values
     */
    public function match(array $values): ?Group
    {
        if ($values === []) {
            return null;
        }

        $wanted = array_map(mb_strtolower(...), $values);

        return Group::query()
            ->whereNotNull('idp_groups')
            ->orderBy('idp_priority')
            ->orderBy('id')
            ->get()
            ->first(fn (Group $group): bool => array_intersect(array_map(mb_strtolower(...), $group->idp_groups ?? []), $wanted) !== []);
    }

    /**
     * The group the user belongs in after this sign-in; null leaves it as
     * it is. Mapping changes nothing while it is off, while the provider
     * sends no groups, or for a user an administrator pinned to a group.
     *
     * @throws IdentityRejected the user is in no mapped group and such users may not sign in
     */
    public function resolve(User $user, ExternalIdentity $identity): ?Group
    {
        if (! $this->enabled || $identity->groups === null || $user->group_pinned) {
            return null;
        }

        $group = $this->match($identity->groups);

        if ($group !== null) {
            return $group;
        }

        return match ($this->unmatched) {
            'default' => Group::default(),
            // Administrators are never locked out by a mapping mistake.
            'reject' => $user->role === UserRole::User
                ? throw new IdentityRejected(RejectionReason::NoMappedGroup)
                : null,
            default => null,
        };
    }
}
