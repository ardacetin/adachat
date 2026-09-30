<?php

namespace App\Models;

use App\Domain\AI\Enums\InputCountMethod;
use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Money\UsdCast;
use App\Domain\Usage\Enums\UsageEventStatus;
use App\Domain\Usage\Enums\UsageEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only financial ledger. Rows are never updated or deleted by the
 * application; corrections are new adjustment rows. Prices are snapshots
 * (USD per million tokens) of the moment the usage happened.
 *
 * @property string $id
 * @property UsageEventType $type
 * @property int $user_id
 * @property int $group_id
 * @property int $budget_period_id
 * @property string|null $reservation_id
 * @property string|null $conversation_id
 * @property string|null $message_id
 * @property int|null $provider_id
 * @property int|null $ai_model_id
 * @property int|null $model_alias_id
 * @property string $source
 * @property int $input_tokens
 * @property int $cached_input_tokens
 * @property int $cache_write_tokens
 * @property int $output_tokens
 * @property int $reasoning_tokens
 * @property string|null $input_price_snapshot
 * @property string|null $cached_input_price_snapshot
 * @property string|null $cache_write_price_snapshot
 * @property string|null $output_price_snapshot
 * @property Usd $input_cost_usd
 * @property Usd $output_cost_usd
 * @property Usd $other_cost_usd
 * @property Usd $total_cost_usd
 * @property bool $is_estimated
 * @property InputCountMethod|null $input_count_method
 * @property int|null $reserved_input_tokens
 * @property string|null $provider_request_id
 * @property UsageEventStatus $status
 * @property string|null $reason
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read BudgetReservation|null $reservation
 */
class UsageEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Usage events are append-only.'));
        static::deleting(fn () => throw new LogicException('Usage events are append-only.'));
    }

    /**
     * @return BelongsTo<BudgetReservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(BudgetReservation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => UsageEventType::class,
            'status' => UsageEventStatus::class,
            'input_count_method' => InputCountMethod::class,
            'input_price_snapshot' => 'decimal:6',
            'cached_input_price_snapshot' => 'decimal:6',
            'cache_write_price_snapshot' => 'decimal:6',
            'output_price_snapshot' => 'decimal:6',
            'input_cost_usd' => UsdCast::class,
            'output_cost_usd' => UsdCast::class,
            'other_cost_usd' => UsdCast::class,
            'total_cost_usd' => UsdCast::class,
            'is_estimated' => 'boolean',
        ];
    }
}
