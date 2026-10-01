<?php

namespace App\Domain\Budget\Exceptions;

/**
 * The institution's monthly cap cannot pay for the request, although the
 * user's own budget could.
 */
final class InstitutionBudgetExhausted extends BudgetException
{
    public function code(): string
    {
        return 'institution_budget_exhausted';
    }
}
