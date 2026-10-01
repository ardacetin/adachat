<?php

namespace App\Models;

use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Money\UsdCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The whole institution's spending for one month, [period_start,
 * period_end) in UTC: the sum of the users' budget periods. Amounts change
 * only inside the budget engine's locked transactions.
 *
 * @property int $id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property Usd $spent_usd
 * @property Usd $reserved_usd
 * @property CarbonImmutable|null $alerted_80_at
 * @property CarbonImmutable|null $alerted_100_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class InstitutionPeriod extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * cap − spent − reserved; negative after an overshoot.
     */
    public function available(Usd $cap): Usd
    {
        return $cap->minus($this->spent_usd)->minus($this->reserved_usd);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'spent_usd' => UsdCast::class,
            'reserved_usd' => UsdCast::class,
            'alerted_80_at' => 'datetime',
            'alerted_100_at' => 'datetime',
        ];
    }
}
