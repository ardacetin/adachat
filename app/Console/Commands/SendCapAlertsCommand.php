<?php

namespace App\Console\Commands;

use App\Domain\Budget\Services\CapAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled every five minutes: e-mails the notification addresses when the
 * institution reaches 80 % and 100 % of its monthly cap (once each).
 */
#[Signature('ada:budget:cap-alerts')]
#[Description('E-mail the institution cap alerts when a threshold is reached')]
class SendCapAlertsCommand extends Command
{
    public function handle(CapAlerts $alerts): int
    {
        $threshold = $alerts->check();

        if ($threshold !== null) {
            $this->components->info("Sent the {$threshold} % cap alert.");
        }

        return self::SUCCESS;
    }
}
