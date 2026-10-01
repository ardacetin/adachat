<?php

namespace App\Domain\Reports;

use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Services\BudgetSummary;
use App\Domain\Budget\Services\InstitutionPeriods;
use App\Domain\Budget\Services\PeriodCalculator;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Mail\MonthlyReportMail;
use App\Models\InstitutionPeriod;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The monthly summary e-mailed to the notification addresses after each
 * month in the institution's time zone: totals, top groups and models,
 * budget overshoots, users at their limit and the cap, with the per-user
 * breakdown and the daily spending as CSV attachments. Usage figures only,
 * never message content.
 */
final class MonthlyReport
{
    public const TOP = 10;

    public function __construct(
        private readonly UsageStatistics $statistics,
        private readonly ReportCsv $csv,
        private readonly InstitutionSettings $institution,
        private readonly InstitutionPeriods $institutionPeriods,
        private readonly ReportSettings $settings,
    ) {}

    /**
     * The month that ended last, as YYYY-MM.
     */
    public function previousMonth(?CarbonImmutable $now = null): string
    {
        return ($now ?? CarbonImmutable::now())->setTimezone($this->institution->timezone)
            ->startOfMonth()->subMonthNoOverflow()->format('Y-m');
    }

    /**
     * Send last month's report unless it was sent already (the scheduler
     * calls this every hour).
     *
     * @return string|null the month sent
     */
    public function sendDue(?CarbonImmutable $now = null): ?string
    {
        $month = $this->previousMonth($now);
        $recipients = $this->institution->notification_emails;

        if ($recipients === [] || ($this->settings->last_monthly_sent !== null && $this->settings->last_monthly_sent >= $month)) {
            return null;
        }

        $this->send($month, $recipients);

        $this->settings->last_monthly_sent = $month;
        $this->settings->save();

        return $month;
    }

    /**
     * @param  list<string>  $recipients
     */
    public function send(string $month, array $recipients): void
    {
        $locale = $this->institution->default_locale;
        $data = $this->data($month);
        $filters = $data['filters'];

        Mail::to($recipients)->locale($locale)->send(new MonthlyReportMail(
            institution: $this->institution->name,
            month: $month,
            summary: $data['summary'],
            files: [
                "ada-usage-user-{$month}.csv" => ReportCsv::toString(fn ($out) => $this->csv->breakdown($out, $filters, 'user', $locale)),
                "ada-usage-day-{$month}.csv" => ReportCsv::toString(fn ($out) => $this->csv->timeline($out, $filters, 'day', $locale)),
            ],
        ));
    }

    /**
     * @return array{filters: ReportFilters, summary: array<string, mixed>}
     */
    public function data(string $month): array
    {
        $timezone = $this->institution->timezone;
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, $timezone);

        if ($start === null) {
            throw new \InvalidArgumentException("Invalid month {$month}.");
        }

        $filters = new ReportFilters($start, $start->endOfMonth()->startOfDay(), $timezone);
        [$utcStart] = PeriodCalculator::monthContaining($start, $timezone);

        $institutionMonth = InstitutionPeriod::query()->where('period_start', $utcStart)->first();
        $cap = $this->institutionPeriods->cap();

        return [
            'filters' => $filters,
            'summary' => [
                'totals' => $this->statistics->totals($filters),
                'groups' => array_slice($this->statistics->breakdown($filters, 'group', self::TOP), 0, self::TOP),
                'models' => array_slice($this->statistics->breakdown($filters, 'model', self::TOP), 0, self::TOP),
                'overshoots' => $this->statistics->overshoots($filters)['count'],
                'users_at_limit' => DB::table('budget_periods')
                    ->where('period_start', $utcStart)
                    ->whereColumn('spent_usd', '>=', 'limit_usd')
                    ->count(),
                // Today's cap against that month's spending.
                'cap' => $cap === null ? null : [
                    'cap_usd' => BudgetSummary::cents($cap, RoundingMode::Down),
                    'percent' => BudgetSummary::percent($institutionMonth?->spent_usd ?? Usd::zero(), $cap),
                ],
            ],
        ];
    }
}
