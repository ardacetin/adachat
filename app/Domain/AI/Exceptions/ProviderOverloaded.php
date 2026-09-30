<?php

namespace App\Domain\AI\Exceptions;

final class ProviderOverloaded extends ProviderException
{
    public function code(): string
    {
        return 'overloaded';
    }

    public function retryable(): bool
    {
        return true;
    }
}
