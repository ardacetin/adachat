<?php

namespace App\Console\Commands;

use App\Domain\Conversations\Services\InterruptedGenerations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Resolves reservations of requests whose PHP process died (OOM, deploy,
 * restart) before settling: partial answers are charged with an estimate,
 * the rest expire. Scheduled every minute.
 */
#[Signature('ada:budget:expire-reservations')]
#[Description('Expire abandoned budget reservations past their deadline')]
class ExpireReservationsCommand extends Command
{
    public function handle(InterruptedGenerations $generations): int
    {
        $total = 0;

        do {
            $expired = $generations->resolve();
            $total += $expired;
        } while ($expired > 0);

        if ($total > 0) {
            $this->components->info("Resolved {$total} abandoned reservation(s).");
        }

        return self::SUCCESS;
    }
}
