<?php

namespace App\Models;

use App\Domain\AI\Enums\InputCountMethod;
use App\Domain\Budget\Enums\ReservationStatus;
use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Money\UsdCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money held for one in-flight request. The UUIDv7 id doubles as the
 * idempotency key for settlement.
 *
 * @property string $id
 * @property int $budget_period_id
 * @property int $user_id
 * @property int $ai_model_id
 * @property Usd $amount_usd
 * @property Usd|null $settled_amount_usd
 * @property int $input_tokens
 * @property InputCountMethod $input_count_method
 * @property string $input_safety_margin
 * @property int $max_output_tokens
 * @property ReservationStatus $status
 * @property string|null $status_reason
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $settled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read BudgetPeriod $budgetPeriod
 * @property-read User $user
 * @property-read AiModel $aiModel
 */
class BudgetReservation extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return BelongsTo<BudgetPeriod, $this>
     */
    public function budgetPeriod(): BelongsTo
    {
        return $this->belongsTo(BudgetPeriod::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_usd' => UsdCast::class,
            'settled_amount_usd' => UsdCast::class,
            'input_count_method' => InputCountMethod::class,
            'input_safety_margin' => 'decimal:4',
            'status' => ReservationStatus::class,
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}
