<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Money\Usd;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Models\BudgetPeriod;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * What a user may see of their own budget in the current period. Read-only:
 * a period that does not exist yet is shown with the limit it would get,
 * without creating it (the first request does).
 *
 * With the institution's "percent" display, no dollar amounts are returned
 * at all, so none can reach the browser.
 */
final class BudgetSummary
{
    public function __construct(private readonly InstitutionSettings $institution) {}

    /**
     * Dates are calendar dates in the institution's time zone.
     *
     * @return array{display: string, percent_used: int, exhausted: bool, period_start: string, resets_on: string, limit_usd?: string, spent_usd?: string, remaining_usd?: string}
     */
    public function for(User $user, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        [$start, $end] = PeriodCalculator::monthContaining($now, $this->institution->timezone);

        $period = BudgetPeriod::query()
            ->where('user_id', $user->id)
            ->where('period_start', $start)
            ->first();

        $limit = $period->limit_usd ?? EffectiveLimit::for($user);
        $spent = $period->spent_usd ?? Usd::zero();
        $reserved = $period->reserved_usd ?? Usd::zero();
        $remaining = $limit->minus($spent)->minus($reserved)->max(Usd::zero());

        $summary = [
            'display' => $this->institution->budget_display === 'percent' ? 'percent' : 'amount',
            'percent_used' => self::percent($spent, $limit),
            'exhausted' => ! $remaining->isPositive(),
            'period_start' => $start->setTimezone($this->institution->timezone)->toDateString(),
            'resets_on' => $end->setTimezone($this->institution->timezone)->toDateString(),
        ];

        if ($summary['display'] === 'amount') {
            $summary['limit_usd'] = self::cents($limit, RoundingMode::Down);
            // Spending is rounded up and the remainder down: never show more than there is.
            $summary['spent_usd'] = self::cents($spent, RoundingMode::Up);
            $summary['remaining_usd'] = self::cents($remaining, RoundingMode::Down);
        }

        return $summary;
    }

    public static function percent(Usd $part, Usd $whole): int
    {
        if (! $whole->isPositive()) {
            return 100;
        }

        $percent = $part->amount->multipliedBy(100)->dividedBy($whole->amount, 0, RoundingMode::Down)->toInt();

        // Any spending shows as at least 1 %.
        return max(0, min(100, $percent === 0 && $part->isPositive() ? 1 : $percent));
    }

    public static function cents(Usd $amount, RoundingMode $rounding): string
    {
        return (string) BigDecimal::of($amount->amount)->toScale(2, $rounding);
    }
}
