<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Budget\Services\BudgetSummary;
use App\Domain\Budget\Services\InstitutionPeriods;
use App\Domain\Budget\Services\PeriodCalculator;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\UsageStatistics;
use App\Http\Controllers\Controller;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The administration overview: this month's headline figures, daily
 * spending, the top models and groups, and budget overshoots.
 */
class DashboardController extends Controller
{
    public function __invoke(UsageStatistics $statistics, InstitutionSettings $institution, InstitutionPeriods $institutionPeriods): Response
    {
        $now = CarbonImmutable::now();
        $timezone = $institution->timezone;
        [$start, $end] = PeriodCalculator::monthContaining($now, $timezone);
        $month = new ReportFilters($start->setTimezone($timezone), $end->setTimezone($timezone)->subDay(), $timezone);

        return Inertia::render('admin/index', [
            'kpis' => $statistics->kpis($timezone, $now),
            'daily' => $statistics->timeline($month),
            'topModels' => array_slice($statistics->breakdown($month, 'model'), 0, 5),
            'topGroups' => array_slice($statistics->breakdown($month, 'group'), 0, 5),
            'overshoots' => $statistics->overshoots($month)['count'],
            'cap' => $this->cap($institutionPeriods, $now),
        ]);
    }

    /**
     * @return array{cap_usd: string, used_usd: string, percent: int, resets_on: string}|null
     */
    private function cap(InstitutionPeriods $periods, CarbonImmutable $now): ?array
    {
        $status = $periods->status($now);

        return $status === null ? null : [
            'cap_usd' => BudgetSummary::cents($status['cap'], RoundingMode::Down),
            'used_usd' => BudgetSummary::cents($status['used'], RoundingMode::Up),
            'percent' => $status['percent'],
            'resets_on' => $status['resets_on']->toDateString(),
        ];
    }
}
