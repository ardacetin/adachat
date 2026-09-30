<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Money\Usd;
use App\Models\User;

/**
 * A user's monthly limit: the individual override, otherwise the policy of
 * the user's group. There is no unlimited budget.
 */
final class EffectiveLimit
{
    public static function for(User $user): Usd
    {
        return $user->monthly_limit_override_usd
            ?? $user->group->budgetPolicy->monthly_limit_usd;
    }
}
