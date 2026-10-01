<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reports\UsageStatistics;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportFilterRequest;
use App\Models\AiModel;
use App\Models\Group;
use App\Models\Provider;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Usage reports with filters: totals, a breakdown by user, group, provider
 * or model, spending over time, overshoots and counter deviation.
 */
class ReportController extends Controller
{
    public function __invoke(ReportFilterRequest $request, UsageStatistics $statistics): Response
    {
        $filters = $request->filters();
        $by = $request->dimension();
        $interval = $request->timelineInterval();

        return Inertia::render('admin/reports/index', [
            'filters' => [...$filters->toArray(), 'by' => $by, 'interval' => $interval],
            'totals' => $statistics->totals($filters),
            'breakdown' => $statistics->breakdown($filters, $by),
            'timeline' => $statistics->timeline($filters, $interval),
            'overshoots' => $statistics->overshoots($filters),
            'deviation' => $statistics->counterDeviation($filters),
            'options' => [
                'groups' => Group::query()->orderBy('name')->get(['id', 'name']),
                'providers' => Provider::query()->orderBy('name')->get(['id', 'name']),
                'models' => AiModel::query()->orderBy('display_name')->get(['id', 'display_name']),
                // The user filter is set from the "by user" breakdown; show who it is.
                'user' => $filters->userId === null ? null : User::query()->whereKey($filters->userId)->first(['id', 'name', 'email']),
            ],
            'topRows' => UsageStatistics::TOP_ROWS,
        ]);
    }
}
