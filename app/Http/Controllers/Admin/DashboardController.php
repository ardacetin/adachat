<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Budget\Services\PeriodCalculator;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\UsageStatistics;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The administration overview: this month's headline figures, daily
 * spending, the top models and groups, and budget overshoots.
 */
class DashboardController extends Controller
{
    public function __invoke(UsageStatistics $statistics, InstitutionSettings $institution): Response
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
        ]);
    }
}
