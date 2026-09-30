<?php

namespace App\Models;

use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Money\UsdCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's spending envelope for one month, [period_start, period_end) in
 * UTC. Amounts change only inside the budget engine's locked transactions.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property Usd $limit_usd
 * @property Usd $spent_usd
 * @property Usd $reserved_usd
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
class BudgetPeriod extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * limit − spent − reserved; negative after an overshoot.
     */
    public function available(): Usd
    {
        return $this->limit_usd->minus($this->spent_usd)->minus($this->reserved_usd);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<BudgetReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(BudgetReservation::class);
    }

    /**
     * @return HasMany<UsageEvent, $this>
     */
    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'limit_usd' => UsdCast::class,
            'spent_usd' => UsdCast::class,
            'reserved_usd' => UsdCast::class,
        ];
    }
}
