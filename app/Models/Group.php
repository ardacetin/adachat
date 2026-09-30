<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Organisational policy unit. Every user belongs to exactly one group.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $budget_policy_id
 * @property int $requests_per_minute
 * @property int $max_concurrent_streams
 * @property bool $is_default
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read BudgetPolicy $budgetPolicy
 * @property-read int|null $users_count withCount('users')
 * @property-read int|null $model_aliases_count withCount('modelAliases')
 */
#[Fillable(['name', 'description', 'budget_policy_id', 'requests_per_minute', 'max_concurrent_streams'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    /**
     * @var array<string, int|bool|null>
     */
    protected $attributes = [
        'description' => null,
        'requests_per_minute' => 20,
        'max_concurrent_streams' => 2,
        'is_default' => false,
    ];

    /**
     * The group new users are placed in. Created by the groups migration.
     */
    public static function default(): self
    {
        return self::query()->where('is_default', true)->firstOrFail();
    }

    /**
     * @return BelongsTo<BudgetPolicy, $this>
     */
    public function budgetPolicy(): BelongsTo
    {
        return $this->belongsTo(BudgetPolicy::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Model aliases members of this group may use.
     *
     * @return BelongsToMany<ModelAlias, $this>
     */
    public function modelAliases(): BelongsToMany
    {
        return $this->belongsToMany(ModelAlias::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'requests_per_minute' => 'integer',
            'max_concurrent_streams' => 'integer',
        ];
    }
}
