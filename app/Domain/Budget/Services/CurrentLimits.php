<?php

namespace App\Domain\Budget\Services;

use App\Domain\Institution\Settings\InstitutionSettings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Apply to the current period" after an admin changed a policy, a group or
 * an override (budget-engine.md §4): users who already have a period this
 * month get their new effective limit; everyone else gets it when their
 * period is created.
 */
final class CurrentLimits
{
    public function __construct(
        private readonly BudgetPeriods $periods,
        private readonly InstitutionSettings $institution,
    ) {}

    /**
     * @param  Builder<User>  $users
     * @return int number of users checked
     */
    public function apply(Builder $users, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        [$start] = PeriodCalculator::monthContaining($now, $this->institution->timezone);
        $count = 0;

        $users->whereHas('budgetPeriods', fn (Builder $query) => $query->where('period_start', $start))
            ->with('group.budgetPolicy')
            ->chunkById(200, function (Collection $chunk) use ($now, &$count): void {
                foreach ($chunk as $user) {
                    $this->periods->applyCurrentLimit($user, $now);
                    $count++;
                }
            });

        return $count;
    }
}
