<?php

namespace App\Domain\AI\Services;

use App\Models\ModelAlias;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which model aliases a user may chat with: enabled aliases assigned to the
 * user's group whose backing model and provider are enabled.
 */
final class AliasAccess
{
    /**
     * @return Collection<int, ModelAlias>
     */
    public function availableFor(User $user): Collection
    {
        return $this->query($user)
            ->with('aiModel.provider')
            ->orderBy('sort_order')
            ->orderBy('slug')
            ->get();
    }

    public function find(User $user, int|string $aliasId): ?ModelAlias
    {
        return $this->query($user)->with('aiModel.provider')->find($aliasId);
    }

    /**
     * @return Builder<ModelAlias>
     */
    private function query(User $user): Builder
    {
        return ModelAlias::query()
            ->where('enabled', true)
            ->whereHas('groups', fn (Builder $query) => $query->whereKey($user->group_id))
            ->whereHas('aiModel', fn (Builder $query) => $query
                ->where('enabled', true)
                ->whereHas('provider', fn (Builder $query) => $query->where('enabled', true)));
    }
}
