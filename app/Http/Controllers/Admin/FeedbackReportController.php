<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reports\FeedbackStatistics;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportFilterRequest;
use App\Models\MessageFeedback;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Satisfaction with the answers, by model alias, model or assistant, for a
 * range of days. Votes only: no messages, conversations or users.
 */
class FeedbackReportController extends Controller
{
    public function __invoke(ReportFilterRequest $request, FeedbackStatistics $statistics): Response
    {
        $per = $request->validate(['per' => ['nullable', Rule::in(FeedbackStatistics::DIMENSIONS)]])['per'] ?? 'alias';
        $filters = $request->filters();

        return Inertia::render('admin/feedback', [
            'filters' => [...array_intersect_key($filters->toArray(), array_flip(['from', 'to'])), 'per' => $per],
            'totals' => $statistics->totals($filters),
            'rows' => $statistics->breakdown($filters, $per),
            'reasons' => MessageFeedback::REASONS,
            'minVotes' => FeedbackStatistics::MIN_VOTES,
        ]);
    }
}
