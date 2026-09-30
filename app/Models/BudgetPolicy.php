<?php

namespace App\Models;

use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Money\UsdCast;
use Carbon\CarbonImmutable;
use Database\Factories\BudgetPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named, reusable monthly limit assigned to groups. There is no unlimited
 * policy: a limit of $0 blocks usage.
 *
 * @property int $id
 * @property string $name
 * @property Usd $monthly_limit_usd
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read int|null $groups_count withCount('groups')
 */
#[Fillable(['name', 'monthly_limit_usd'])]
class BudgetPolicy extends Model
{
    /** @use HasFactory<BudgetPolicyFactory> */
    use HasFactory;

    /**
     * @return HasMany<Group, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_limit_usd' => UsdCast::class,
        ];
    }
}
