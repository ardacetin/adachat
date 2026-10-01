<?php

namespace App\Domain\Budget\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Budget\Data\Discrepancy;
use App\Domain\Budget\Enums\ReservationStatus;
use App\Domain\Budget\Money\Usd;
use App\Models\BudgetPeriod;
use App\Models\InstitutionPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Checks the denormalized period totals against their sources:
 *
 *   spent_usd    == SUM(usage_events.total_cost_usd)
 *   reserved_usd == SUM(active budget_reservations.amount_usd)
 *
 * and each institution month against the sum of the users' periods of
 * that month. Differences are reported, never silently corrected.
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
     * Institution months whose totals differ from the sum of the users'
     * periods. The period id is the institution_periods id, the user 0.
     *
     * @return list<Discrepancy>
     */
    public function checkInstitution(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT i.id, i.spent_usd, i.reserved_usd,
                   COALESCE(SUM(p.spent_usd), 0) AS expected_spent,
                   COALESCE(SUM(p.reserved_usd), 0) AS expected_reserved
            FROM institution_periods i
            LEFT JOIN budget_periods p ON p.period_start = i.period_start
            GROUP BY i.id, i.spent_usd, i.reserved_usd
            HAVING i.spent_usd <> expected_spent OR i.reserved_usd <> expected_reserved
            ORDER BY i.id
            SQL);

        $discrepancies = [];

        foreach ($rows as $row) {
            foreach (['spent_usd' => 'expected_spent', 'reserved_usd' => 'expected_reserved'] as $column => $expectedKey) {
                $stored = Usd::of((string) $row->{$column});
                $expected = Usd::of((string) $row->{$expectedKey});

                if (! $stored->equals($expected)) {
                    $discrepancies[] = new Discrepancy((int) $row->id, 0, 'institution_'.$column, $stored, $expected);
                }
            }
        }

        return $discrepancies;
    }

    /**
     * Recompute an institution month from the users' periods (derived data,
     * so both totals are safe to rewrite). Takes the same lock order as the
     * budget engine: the users' periods first.
     */
    public function fixInstitution(int $institutionPeriodId): void
    {
        DB::transaction(function () use ($institutionPeriodId): void {
            $start = InstitutionPeriod::query()->whereKey($institutionPeriodId)->value('period_start');

            $sums = DB::table('budget_periods')
                ->where('period_start', $start)
                ->lockForUpdate()
                ->selectRaw('COALESCE(SUM(spent_usd), 0) AS spent, COALESCE(SUM(reserved_usd), 0) AS reserved')
                ->first();

            $institution = InstitutionPeriod::query()->whereKey($institutionPeriodId)->lockForUpdate()->firstOrFail();
            $old = ['spent_usd' => $institution->spent_usd->toString(), 'reserved_usd' => $institution->reserved_usd->toString()];

            $institution->spent_usd = Usd::of((string) ($sums->spent ?? '0'));
            $institution->reserved_usd = Usd::of((string) ($sums->reserved ?? '0'));
            $institution->save();

            $this->audit->record('budget.institution_recomputed', $institution, $old, [
                'spent_usd' => $institution->spent_usd->toString(),
                'reserved_usd' => $institution->reserved_usd->toString(),
            ]);
        }, 3);
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
