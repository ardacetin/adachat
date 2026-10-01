<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reports\ReportCsv;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportFilterRequest;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reports as CSV with the same filters as the page: the full breakdown
 * (all rows) or the timeline. Totals per user, group, provider or model
 * only; never message content.
 */
class ReportExportController extends Controller
{
    public function __invoke(ReportFilterRequest $request, ReportCsv $csv, AuditLogger $audit): StreamedResponse
    {
        $dataset = $request->query('dataset', 'breakdown');

        if (! in_array($dataset, ['breakdown', 'timeline'], true)) {
            throw ValidationException::withMessages(['dataset' => __('validation.in', ['attribute' => 'dataset'])]);
        }

        $filters = $request->filters();
        $locale = app()->getLocale();
        $kind = $dataset === 'breakdown' ? $request->dimension() : $request->timelineInterval();
        $name = "ada-usage-{$kind}-{$filters->from->toDateString()}_{$filters->to->toDateString()}.csv";

        // Per-user exports contain names and e-mail addresses.
        $audit->record('reports.exported', null, [], [...$filters->toArray(), 'dataset' => $dataset, 'kind' => $kind]);

        return response()->streamDownload(function () use ($csv, $dataset, $filters, $kind, $locale): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            $dataset === 'breakdown'
                ? $csv->breakdown($out, $filters, $kind, $locale)
                : $csv->timeline($out, $filters, $kind, $locale);

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
