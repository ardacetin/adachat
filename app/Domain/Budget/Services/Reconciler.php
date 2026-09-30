<?php

namespace App\Domain\Budget\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Budget\Data\Discrepancy;
use App\Domain\Budget\Enums\ReservationStatus;
use App\Domain\Budget\Money\Usd;
use App\Models\BudgetPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Checks the denormalized period totals against their sources:
 *
 *   spent_usd    == SUM(usage_events.total_cost_usd)
 *   reserved_usd == SUM(active budget_reservations.amount_usd)
 *
 * Differences are reported, never silently corrected.
 */
final class Reconciler
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return list<Discrepancy>
     */
    public function check(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT p.id, p.user_id, p.spent_usd, p.reserved_usd,
                   COALESCE(e.spent, 0) AS expected_spent,
                   COALESCE(r.reserved, 0) AS expected_reserved
            FROM budget_periods p
            LEFT JOIN (
                SELECT budget_period_id, SUM(total_cost_usd) AS spent
                FROM usage_events GROUP BY budget_period_id
            ) e ON e.budget_period_id = p.id
            LEFT JOIN (
                SELECT budget_period_id, SUM(amount_usd) AS reserved
                FROM budget_reservations WHERE status = ? GROUP BY budget_period_id
            ) r ON r.budget_period_id = p.id
            WHERE p.spent_usd <> COALESCE(e.spent, 0)
               OR p.reserved_usd <> COALESCE(r.reserved, 0)
            ORDER BY p.id
            SQL, [ReservationStatus::Active->value]);

        $discrepancies = [];

        foreach ($rows as $row) {
            foreach (['spent_usd' => 'expected_spent', 'reserved_usd' => 'expected_reserved'] as $column => $expectedKey) {
                $stored = Usd::of((string) $row->{$column});
                $expected = Usd::of((string) $row->{$expectedKey});

                if (! $stored->equals($expected)) {
                    $discrepancies[] = new Discrepancy((int) $row->id, (int) $row->user_id, $column, $stored, $expected);
                }
            }
        }

        return $discrepancies;
    }

    /**
     * Recompute reserved_usd from the active reservations (safe: it is
     * derived data). spent_usd is never rewritten; use an adjustment.
     */
    public function fixReserved(int $periodId): void
    {
        DB::transaction(function () use ($periodId): void {
            $period = BudgetPeriod::query()->whereKey($periodId)->lockForUpdate()->firstOrFail();

            $expected = Usd::of((string) (DB::table('budget_reservations')
                ->where('budget_period_id', $periodId)
                ->where('status', ReservationStatus::Active->value)
                ->lockForUpdate()
                ->sum('amount_usd')));

            if ($period->reserved_usd->equals($expected)) {
                return;
            }

            $old = $period->reserved_usd;
            $period->reserved_usd = $expected;
            $period->save();

            $this->audit->record('budget.reserved_recomputed', $period, ['reserved_usd' => $old->toString()], ['reserved_usd' => $expected->toString()]);
        }, 3);
    }
}
