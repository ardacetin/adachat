<?php

namespace App\Console\Commands;

use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Reports\MonthlyReport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled every hour: once a month has ended in the institution's time
 * zone, e-mails its report to the notification addresses (once). With
 * --month, sends that month on demand (to --to or the usual addresses)
 * without touching the schedule.
 */
#[Signature('ada:reports:monthly {--month= : YYYY-MM, e.g. 2026-09} {--to=* : Send to these addresses instead}')]
#[Description('E-mail the monthly usage report')]
class SendMonthlyReportCommand extends Command
{
    public function handle(MonthlyReport $report, InstitutionSettings $institution): int
    {
        $month = $this->option('month');

        if ($month === null) {
            $sent = $report->sendDue();

            if ($sent !== null) {
                $this->components->info("Sent the report for {$sent}.");
            }

            return self::SUCCESS;
        }

        if (! is_string($month) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            $this->components->error('Use --month=YYYY-MM.');

            return self::FAILURE;
        }

        /** @var list<string> $to */
        $to = array_values(array_filter((array) $this->option('to'), 'is_string'));
        $recipients = $to !== [] ? $to : $institution->notification_emails;

        if ($recipients === []) {
            $this->components->error('No recipients: set notification e-mails in Admin > Institution or pass --to.');

            return self::FAILURE;
        }

        $report->send($month, $recipients);
        $this->components->info("Sent the report for {$month} to ".implode(', ', $recipients).'.');

        return self::SUCCESS;
    }
}
