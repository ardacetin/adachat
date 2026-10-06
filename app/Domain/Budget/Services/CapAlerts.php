<?php

namespace App\Domain\Budget\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Mail\CapAlert;
use App\Models\InstitutionPeriod;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-mails the notification addresses once per month when the institution's
 * spending (spent + reserved) reaches 80 % and 100 % of the cap. Runs from
 * the scheduler, never inside a chat request.
 */
final class CapAlerts
{
    public const THRESHOLDS = [100, 80];

    public function __construct(
        private readonly InstitutionPeriods $periods,
        private readonly InstitutionSettings $institution,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int|null the threshold that was alerted, if any
     */
    public function check(?CarbonImmutable $now = null): ?int
    {
        $status = $this->periods->status($now);
        $recipients = $this->institution->notification_emails;

        // Without recipients nothing is stamped: adding them later still
        // sends this month's alert.
        if ($status === null || $status['period'] === null || $recipients === []) {
            return null;
        }

        foreach (self::THRESHOLDS as $threshold) {
            if ($status['percent'] < $threshold) {
                continue;
            }

            $column = "alerted_{$threshold}_at";
            $before = ['alerted_80_at' => $status['period']->alerted_80_at, 'alerted_100_at' => $status['period']->alerted_100_at];
            $claimed = InstitutionPeriod::query()
                ->whereKey($status['period']->id)
                ->whereNull($column)
                ->update([$column => CarbonImmutable::now(), ...($threshold === 100 ? ['alerted_80_at' => CarbonImmutable::now()] : [])]);

            // Already sent (or claimed by a parallel run).
            if ($claimed === 0) {
                return null;
            }

            $values = [
                'threshold' => $threshold,
                'used_usd' => BudgetSummary::cents($status['used'], RoundingMode::Up),
                'cap_usd' => BudgetSummary::cents($status['cap'], RoundingMode::Down),
            ];

            try {
                Mail::to($recipients)
                    ->locale($this->institution->default_locale)
                    ->send(new CapAlert(
                        institution: $this->institution->name,
                        threshold: $threshold,
                        usedUsd: $values['used_usd'],
                        capUsd: $values['cap_usd'],
                        resetsOn: $status['resets_on']->toDateString(),
                    ));
            } catch (Throwable $failed) {
                // Not sent: the next run tries again.
                InstitutionPeriod::query()->whereKey($status['period']->id)->update($before);

                throw $failed;
            }

            $this->audit->record('budget.cap_alert', 'InstitutionSettings', [], $values);

            return $threshold;
        }

        return null;
    }

    /**
     * After the cap changed: this month's alerts may be due again.
     */
    public function reset(?CarbonImmutable $now = null): void
    {
        $period = $this->periods->status($now)['period'] ?? null;

        if ($period === null) {
            [$start] = PeriodCalculator::monthContaining($now ?? CarbonImmutable::now(), $this->institution->timezone);
            InstitutionPeriod::query()->where('period_start', $start)->update(['alerted_80_at' => null, 'alerted_100_at' => null]);

            return;
        }

        $period->forceFill(['alerted_80_at' => null, 'alerted_100_at' => null])->save();
    }
}
