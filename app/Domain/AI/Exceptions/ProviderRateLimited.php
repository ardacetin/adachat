<?php

namespace App\Domain\AI\Exceptions;

final class ProviderRateLimited extends ProviderException
{
    public function code(): string
    {
        return 'rate_limited';
    }

    public function retryable(): bool
    {
        return true;
    }
}
