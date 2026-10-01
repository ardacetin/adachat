<?php

namespace App\Console\Commands;

use App\Domain\Budget\Services\Reconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('ada:budget:reconcile {--fix-reserved : Recompute reserved amounts from active reservations and institution months from the users\' periods}')]
#[Description('Compare budget period totals with the usage ledger and active reservations')]
class ReconcileBudgetsCommand extends Command
{
    public function handle(Reconciler $reconciler): int
    {
        $discrepancies = [...$reconciler->check(), ...$reconciler->checkInstitution()];

        if ($discrepancies === []) {
            $this->components->info('Budget periods match the ledger.');

            return self::SUCCESS;
        }

        $this->table(
            ['Period', 'User', 'Column', 'Stored', 'Expected'],
            array_map(fn ($d) => [$d->periodId, $d->userId, $d->column, $d->stored->toString(), $d->expected->toString()], $discrepancies),
        );

        foreach ($discrepancies as $discrepancy) {
            Log::warning('Budget reconciliation mismatch.', [
                'budget_period_id' => $discrepancy->periodId,
                'column' => $discrepancy->column,
                'stored' => $discrepancy->stored->toString(),
                'expected' => $discrepancy->expected->toString(),
            ]);
        }

        if (! $this->option('fix-reserved')) {
            return self::FAILURE;
        }

        $fixable = array_filter($discrepancies, fn ($d) => $d->column === 'reserved_usd' || str_starts_with($d->column, 'institution_'));
        $institutionIds = [];

        foreach ($fixable as $discrepancy) {
            if (str_starts_with($discrepancy->column, 'institution_')) {
                $institutionIds[$discrepancy->periodId] = true;
            } else {
                $reconciler->fixReserved($discrepancy->periodId);
            }
        }

        // After the users' periods: institution months are their sums.
        foreach (array_keys($institutionIds) as $id) {
            $reconciler->fixInstitution($id);
        }

        $this->components->info(count($fixable).' reserved amount(s) recomputed.');

        // Spent mismatches are never rewritten automatically.
        return count($fixable) === count($discrepancies) ? self::SUCCESS : self::FAILURE;
    }
}
