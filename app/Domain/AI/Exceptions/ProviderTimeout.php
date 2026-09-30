<?php

namespace App\Domain\AI\Exceptions;

final class ProviderTimeout extends ProviderException
{
    public function code(): string
    {
        return 'timeout';
    }

    public function retryable(): bool
    {
        return true;
    }
}
