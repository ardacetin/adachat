<?php

namespace App\Console\Commands;

use App\Domain\Retention\RetentionPruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ada:retention:prune {--dry-run : Only count what would be deleted}')]
#[Description('Delete conversations and usage records past their retention period')]
class PruneRetentionCommand extends Command
{
    public function handle(RetentionPruner $pruner): int
    {
        $counts = $pruner->prune((bool) $this->option('dry-run'));

        foreach ($counts as $what => $count) {
            $this->components->twoColumnDetail(str_replace('_', ' ', $what), (string) $count);
        }

        if ($this->option('dry-run')) {
            $this->components->info('Dry run: nothing was deleted.');
        }

        return self::SUCCESS;
    }
}
