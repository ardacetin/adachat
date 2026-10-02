<?php

namespace App\Domain\Budget\Services;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Mail\UserBudgetAlert;
use App\Models\BudgetPeriod;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * E-mails users once per period when their spending reaches 80 % and 100 %
 * of their monthly limit. Runs from the scheduler, never inside a chat
 * request; the chat shows the same thresholds at once from the budget
 * summary. When a limit is raised below a threshold that was already
 * announced, the threshold is announced again when it is reached.
 */
final class UserBudgetAlerts
{
    public const THRESHOLDS = [100, 80];

    public function __construct(private readonly InstitutionSettings $institution) {}

    /**
     * @return int the number of e-mails sent
     */
    public function check(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $this->rearm($now);

        if (! $this->institution->user_budget_emails) {
            return 0;
        }

        $sent = 0;

        foreach (self::THRESHOLDS as $threshold) {
            $column = "alerted_{$threshold}_at";

            $this->current($now)
                ->whereNull($column)
                ->whereRaw('spent_usd >= limit_usd * ?', [$threshold / 100])
                ->with('user')
                ->chunkById(200, function (Collection $periods) use ($threshold, $column, &$sent): void {
                    foreach ($periods as $period) {
                        // Claimed first, so a parallel run sends nothing twice;
                        // the 100 % alert also covers 80 %.
                        $claimed = BudgetPeriod::query()
                            ->whereKey($period->id)
                            ->whereNull($column)
                            ->update([$column => CarbonImmutable::now(), ...($threshold === 100 ? ['alerted_80_at' => CarbonImmutable::now()] : [])]);

                        $user = $period->user;

                        if ($claimed === 0 || ! $user->budget_emails || $user->status !== UserStatus::Active) {
                            continue;
                        }

                        $amounts = $this->institution->budget_display === 'percent' ? null : [
                            'spent' => BudgetSummary::cents($period->spent_usd, RoundingMode::Up),
                            'limit' => BudgetSummary::cents($period->limit_usd, RoundingMode::Down),
                        ];

                        Mail::to($user->email)
                            ->locale($user->locale ?? $this->institution->default_locale)
                            ->send(new UserBudgetAlert(
                                institution: $this->institution->name,
                                threshold: $threshold,
                                amounts: $amounts,
                                resetsOn: $period->period_end->setTimezone($this->institution->timezone)->toDateString(),
                            ));

                        $sent++;
                    }
                });
        }

        return $sent;
    }

    /**
     * Periods of the current month with a limit to measure against.
     *
     * @return Builder<BudgetPeriod>
     */
    private function current(CarbonImmutable $now): Builder
    {
        return BudgetPeriod::query()
            ->where('period_start', '<=', $now)
            ->where('period_end', '>', $now)
            ->where('limit_usd', '>', 0);
    }

    /**
     * A raised limit (or an adjustment) can bring spending back under a
     * threshold: that threshold is announced again when it is reached.
     */
    private function rearm(CarbonImmutable $now): void
    {
        $this->current($now)
            ->whereNotNull('alerted_100_at')
            ->whereRaw('spent_usd < limit_usd')
            ->update(['alerted_100_at' => null]);

        $this->current($now)
            ->whereNotNull('alerted_80_at')
            ->whereRaw('spent_usd < limit_usd * 0.8')
            ->update(['alerted_80_at' => null]);
    }
}
