<?php

namespace App\Console\Commands;

use App\Domain\Budget\Services\BudgetEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Frees money held by requests whose PHP process died (OOM, deploy,
 * restart) before settling. Scheduled every minute.
 */
#[Signature('ada:budget:expire-reservations')]
#[Description('Expire abandoned budget reservations past their deadline')]
class ExpireReservationsCommand extends Command
{
    public function handle(BudgetEngine $engine): int
    {
        $total = 0;

        do {
            $expired = $engine->expireStale();
            $total += $expired;
        } while ($expired > 0);

        if ($total > 0) {
            $this->components->info("Expired {$total} reservation(s).");
        }

        return self::SUCCESS;
    }
}
