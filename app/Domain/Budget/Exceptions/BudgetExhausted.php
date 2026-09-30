<?php

namespace App\Domain\Budget\Exceptions;

/**
 * The remaining budget cannot pay for the counted input plus a useful
 * minimum of output.
 */
final class BudgetExhausted extends BudgetException
{
    public function code(): string
    {
        return 'budget_exhausted';
    }
}
