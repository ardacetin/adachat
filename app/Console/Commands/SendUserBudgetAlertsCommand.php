<?php

namespace App\Console\Commands;

use App\Domain\Budget\Services\UserBudgetAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled every five minutes: e-mails users who reached 80 % or 100 % of
 * their monthly budget (once each per month).
 */
#[Signature('ada:budget:user-alerts')]
#[Description('E-mail users who reached 80 % or 100 % of their monthly budget')]
class SendUserBudgetAlertsCommand extends Command
{
    public function handle(UserBudgetAlerts $alerts): int
    {
        $sent = $alerts->check();

        if ($sent > 0) {
            $this->components->info("Sent {$sent} budget alert(s).");
        }

        return self::SUCCESS;
    }
}
