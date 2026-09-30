<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\UsageStatistics;
use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Group;
use App\Models\Provider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Usage reports with filters: totals, a breakdown by user, group, provider
 * or model, spending over time, overshoots and counter deviation.
 */
class ReportController extends Controller
{
    private const MAX_DAYS = 366;

    public function __invoke(Request $request, UsageStatistics $statistics, InstitutionSettings $institution): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'user_id' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'integer'],
            'provider_id' => ['nullable', 'integer'],
            'ai_model_id' => ['nullable', 'integer'],
            'by' => ['nullable', Rule::in(UsageStatistics::DIMENSIONS)],
            'interval' => ['nullable', Rule::in(['day', 'month'])],
        ]);

        $timezone = $institution->timezone;
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'], $timezone) : $today->startOfMonth();
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'], $timezone) : $today;

        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['to' => __('admin.report_range_order')]);
        }

        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => __('admin.report_range_too_long', ['days' => self::MAX_DAYS])]);
        }

        $filters = new ReportFilters(
            $from,
            $to,
            $timezone,
            userId: isset($validated['user_id']) ? (int) $validated['user_id'] : null,
            groupId: isset($validated['group_id']) ? (int) $validated['group_id'] : null,
            providerId: isset($validated['provider_id']) ? (int) $validated['provider_id'] : null,
            aiModelId: isset($validated['ai_model_id']) ? (int) $validated['ai_model_id'] : null,
        );

        $by = $validated['by'] ?? 'model';
        // Long ranges read better per month.
        $interval = $validated['interval'] ?? ($from->diffInDays($to) > 62 ? 'month' : 'day');

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
