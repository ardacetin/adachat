<?php

namespace App\Domain\AI\Exceptions;

final class ProviderUnavailable extends ProviderException
{
    public function code(): string
    {
        return 'provider_unavailable';
    }

    public function retryable(): bool
    {
        return true;
    }
}
