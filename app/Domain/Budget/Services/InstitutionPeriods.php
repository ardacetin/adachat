<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Money\Usd;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Models\InstitutionPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lazily created institution months, like the users' periods: the first
 * request of a month creates the row.
 */
final class InstitutionPeriods
{
    public function __construct(private readonly InstitutionSettings $institution) {}

    /**
     * The institution month containing $now, created if missing.
     *
     * Must run OUTSIDE the locking transaction (see BudgetPeriods::current).
     */
    public function current(?CarbonImmutable $now = null): InstitutionPeriod
    {
        [$start, $end] = PeriodCalculator::monthContaining($now ?? CarbonImmutable::now(), $this->institution->timezone);

        return $this->forMonth($start, $end);
    }

    /**
     * The institution row for a user period's month.
     */
    public function forMonth(CarbonImmutable $start, CarbonImmutable $end): InstitutionPeriod
    {
        $now = CarbonImmutable::now();

        DB::statement(
            'INSERT INTO institution_periods (period_start, period_end, spent_usd, reserved_usd, created_at, updated_at)
             VALUES (?, ?, 0, 0, ?, ?)
             ON DUPLICATE KEY UPDATE id = id',
            [$start, $end, $now, $now],
        );

        return InstitutionPeriod::query()->where('period_start', $start)->firstOrFail();
    }

    /**
     * The current month against the cap, for the dashboard and the alerts;
     * null without a cap. Read-only: creates no row.
     *
     * @return array{period: InstitutionPeriod|null, cap: Usd, used: Usd, percent: int, resets_on: CarbonImmutable}|null
     */
    public function status(?CarbonImmutable $now = null): ?array
    {
        $cap = $this->cap();

        if ($cap === null) {
            return null;
        }

        [$start, $end] = PeriodCalculator::monthContaining($now ?? CarbonImmutable::now(), $this->institution->timezone);
        $period = InstitutionPeriod::query()->where('period_start', $start)->first();
        $used = $period === null ? Usd::zero() : $period->spent_usd->plus($period->reserved_usd);

        return [
            'period' => $period,
            'cap' => $cap,
            'used' => $used,
            'percent' => BudgetSummary::percent($used, $cap),
            'resets_on' => $end->setTimezone($this->institution->timezone),
        ];
    }

    /**
     * The configured cap, or null when there is none.
     */
    public function cap(): ?Usd
    {
        $cap = $this->institution->monthly_cap_usd;

        return $cap === null || trim($cap) === '' ? null : Usd::of($cap);
    }
}
