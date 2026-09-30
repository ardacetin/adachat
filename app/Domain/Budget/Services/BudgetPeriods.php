<?php

namespace App\Domain\Budget\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Models\BudgetPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lazily created monthly periods. There is no rollover job: the first
 * request of a new month creates a fresh row with the limit valid then.
 */
final class BudgetPeriods
{
    public function __construct(
        private readonly InstitutionSettings $institution,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The user's period containing $now, created if missing.
     *
     * Must run OUTSIDE the locking transaction: the upsert auto-commits, so
     * the later SELECT … FOR UPDATE locks an existing index record instead
     * of a gap (two gap locks on the same new row would deadlock).
     */
    public function current(User $user, ?CarbonImmutable $now = null): BudgetPeriod
    {
        $now ??= CarbonImmutable::now();
        [$start, $end] = PeriodCalculator::monthContaining($now, $this->institution->timezone);

        // ON DUPLICATE KEY UPDATE id = id is a no-op on conflict. INSERT
        // IGNORE is avoided: it would downgrade every error to a warning.
        DB::statement(
            'INSERT INTO budget_periods (user_id, period_start, period_end, limit_usd, spent_usd, reserved_usd, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, 0, ?, ?)
             ON DUPLICATE KEY UPDATE id = id',
            [$user->id, $start, $end, EffectiveLimit::for($user)->toString(), $now, $now],
        );

        return BudgetPeriod::query()
            ->where('user_id', $user->id)
            ->where('period_start', $start)
            ->firstOrFail();
    }

    /**
     * Copy the user's current effective limit into the open period, e.g.
     * after an admin changed the policy, group or override ("apply to the
     * current period"). Past periods are never changed.
     */
    public function applyCurrentLimit(User $user, ?CarbonImmutable $now = null): BudgetPeriod
    {
        $period = $this->current($user, $now);

        return DB::transaction(function () use ($user, $period): BudgetPeriod {
            $locked = BudgetPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            $old = $locked->limit_usd;
            $new = EffectiveLimit::for($user);

            if (! $old->equals($new)) {
                $locked->limit_usd = $new;
                $locked->save();

                $this->audit->record('budget.limit_applied', $user, ['limit_usd' => $old->toString()], ['limit_usd' => $new->toString()]);
            }

            return $locked;
        }, attempts: 3);
    }
}
