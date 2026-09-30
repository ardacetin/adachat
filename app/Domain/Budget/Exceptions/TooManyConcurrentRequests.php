<?php

namespace App\Domain\Budget\Exceptions;

final class TooManyConcurrentRequests extends BudgetException
{
    public function code(): string
    {
        return 'too_many_concurrent_requests';
    }
}
