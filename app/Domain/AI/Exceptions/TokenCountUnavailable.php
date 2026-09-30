<?php

namespace App\Domain\AI\Exceptions;

final class TokenCountUnavailable extends ProviderException
{
    public function code(): string
    {
        return 'token_count_unavailable';
    }

    public function retryable(): bool
    {
        return true;
    }
}
