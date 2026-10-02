<?php

namespace App\Domain\Assistants;

use App\Domain\AI\Services\AliasAccess;
use App\Models\Assistant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which assistants a user may use: enabled assistants assigned to the
 * user's group whose model alias the user may use as well (AliasAccess).
 */
final class AssistantAccess
{
    public function __construct(private readonly AliasAccess $aliases) {}

    /**
     * @return Collection<int, Assistant>
     */
    public function availableFor(User $user): Collection
    {
        return $this->query($user)->orderBy('sort_order')->orderBy('slug')->get();
    }

    public function find(User $user, int|string $assistantId): ?Assistant
    {
        return $this->query($user)->find($assistantId);
    }

    /**
     * @return Builder<Assistant>
     */
    private function query(User $user): Builder
    {
        $aliasIds = $this->aliases->availableFor($user)->modelKeys();

        return Assistant::query()
            ->with('modelAlias.aiModel.provider')
            ->where('enabled', true)
            ->whereIn('model_alias_id', $aliasIds)
            ->whereHas('groups', fn (Builder $query) => $query->whereKey($user->group_id));
    }
}
