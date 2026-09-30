<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Services\BudgetPeriods;
use App\Domain\Budget\Services\BudgetSummary;
use App\Domain\Budget\Services\EffectiveLimit;
use App\Models\User;
use Brick\Math\RoundingMode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Individual monthly limit for one user (an override of the group policy)
 * until the users screen arrives (M8). The new limit applies to the current
 * period unless --next-period is given.
 */
#[Signature('ada:user:budget {email : E-mail address of the user} {--limit= : Monthly limit in USD, e.g. 25 or 12.50 (0 blocks usage)} {--clear : Remove the override and use the group policy again} {--next-period : Do not change the current period}')]
#[Description('Set or remove a user\'s individual monthly budget')]
class UserBudgetCommand extends Command
{
    public function handle(AuditLogger $audit, BudgetPeriods $periods): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No user with the e-mail address {$email}.");

            return self::FAILURE;
        }

        $limit = $this->option('limit');

        if ($this->option('clear') === (is_string($limit) && $limit !== '')) {
            $this->components->error('Give either --limit=<USD> or --clear.');

            return self::FAILURE;
        }

        if (! $this->option('clear')) {
            $validator = Validator::make(['limit' => $limit], ['limit' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100000']]);

            if ($validator->fails()) {
                $this->components->error($validator->errors()->first('limit'));

                return self::FAILURE;
            }
        }

        $old = $user->monthly_limit_override_usd;
        $user->monthly_limit_override_usd = $this->option('clear') ? null : Usd::of((string) $limit);
        $user->save();

        $audit->record(
            'user.budget_override_changed',
            $user,
            ['monthly_limit_override_usd' => $old?->toString()],
            ['monthly_limit_override_usd' => $user->monthly_limit_override_usd?->toString(), 'via' => 'ada:user:budget'],
        );

        if (! $this->option('next-period')) {
            $periods->applyCurrentLimit($user);
        }

        $this->components->info(sprintf(
            '%s now has a monthly limit of $%s (%s).',
            $user->email,
            BudgetSummary::cents(EffectiveLimit::for($user->refresh()), RoundingMode::Down),
            $user->monthly_limit_override_usd === null ? 'group policy' : 'individual',
        ));

        return self::SUCCESS;
    }
}
