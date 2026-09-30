<?php

namespace App\Domain\Usage;

use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Services\BudgetSummary;
use App\Domain\Budget\Services\PeriodCalculator;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Usage\Enums\UsageEventType;
use App\Models\BudgetPeriod;
use App\Models\ModelAlias;
use App\Models\UsageEvent;
use App\Models\User;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * A user's own usage for the usage page: the current month by model and by
 * day, and the previous months. Charges only; admin adjustments change the
 * budget but are not usage. In the "percent" display, costs are expressed
 * as a share of the monthly limit and no dollar amounts are returned.
 */
final class UsageReport
{
    private const PAST_MONTHS = 6;

    public function __construct(
        private readonly InstitutionSettings $institution,
        private readonly BudgetSummary $summary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $summary = $this->summary->for($user, $now);
        [$start] = PeriodCalculator::monthContaining($now, $this->institution->timezone);

        $period = BudgetPeriod::query()->where('user_id', $user->id)->where('period_start', $start)->first();
        $limit = $period === null ? null : $period->limit_usd;
        $amounts = $summary['display'] === 'amount';

        $cost = fn (Usd $value): array => $amounts
            ? ['cost_usd' => BudgetSummary::cents($value, RoundingMode::Up)]
            : ['percent_of_limit' => $limit === null ? 0 : BudgetSummary::percent($value, $limit)];

        return [
            'summary' => $summary,
            'by_model' => $period === null ? [] : $this->byModel($period, $cost),
            'by_day' => $period === null ? [] : $this->byDay($period, $cost),
            'months' => $this->months($user, $start, $amounts),
        ];
    }

    /**
     * @param  callable(Usd): array<string, mixed>  $cost
     * @return list<array<string, mixed>>
     */
    private function byModel(BudgetPeriod $period, callable $cost): array
    {
        $rows = UsageEvent::query()
            ->where('budget_period_id', $period->id)
            ->where('type', UsageEventType::Charge)
            ->groupBy('model_alias_id')
            ->selectRaw('model_alias_id, COUNT(*) AS requests, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(total_cost_usd) AS cost')
            ->orderByDesc('cost')
            ->toBase()
            ->get();

        $aliases = ModelAlias::query()->whereIn('id', $rows->pluck('model_alias_id')->filter())->get()->keyBy('id');
        $locale = app()->getLocale();

        return array_values($rows->map(fn (stdClass $row) => [
            'alias' => isset($row->model_alias_id) && $aliases->has($row->model_alias_id)
                ? $aliases->get($row->model_alias_id)?->localizedName($locale)
                : null,
            'requests' => (int) $row->requests,
            'input_tokens' => (int) $row->input_tokens,
            'output_tokens' => (int) $row->output_tokens,
            ...$cost(Usd::of((string) $row->cost)),
        ])->all());
    }

    /**
     * Days in the institution's time zone, oldest first.
     *
     * @param  callable(Usd): array<string, mixed>  $cost
     * @return list<array<string, mixed>>
     */
    private function byDay(BudgetPeriod $period, callable $cost): array
    {
        /** @var array<string, array{requests: int, cost: Usd}> $days */
        $days = [];

        UsageEvent::query()
            ->where('budget_period_id', $period->id)
            ->where('type', UsageEventType::Charge)
            ->select(['id', 'created_at', 'total_cost_usd'])
            ->lazyById(1000, 'id')
            ->each(function (UsageEvent $event) use (&$days): void {
                $day = $event->created_at->setTimezone($this->institution->timezone)->toDateString();
                $days[$day] ??= ['requests' => 0, 'cost' => Usd::zero()];
                $days[$day]['requests']++;
                $days[$day]['cost'] = $days[$day]['cost']->plus($event->total_cost_usd);
            });

        ksort($days);

        return array_map(
            fn (string $day, array $totals) => ['date' => $day, 'requests' => $totals['requests'], ...$cost($totals['cost'])],
            array_keys($days),
            $days,
        );
    }

    /**
     * Previous months, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function months(User $user, CarbonImmutable $currentStart, bool $amounts): array
    {
        return array_values(BudgetPeriod::query()
            ->where('user_id', $user->id)
            ->where('period_start', '<', $currentStart)
            ->orderByDesc('period_start')
            ->limit(self::PAST_MONTHS)
            ->get()
            ->map(fn (BudgetPeriod $period) => [
                'month' => $period->period_start->setTimezone($this->institution->timezone)->format('Y-m'),
                'percent_used' => BudgetSummary::percent($period->spent_usd, $period->limit_usd),
                ...($amounts ? [
                    'spent_usd' => BudgetSummary::cents($period->spent_usd, RoundingMode::Up),
                    'limit_usd' => BudgetSummary::cents($period->limit_usd, RoundingMode::Down),
                ] : []),
            ])
            ->all());
    }
}
