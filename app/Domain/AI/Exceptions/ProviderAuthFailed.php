<?php

namespace App\Domain\AI\Exceptions;

final class ProviderAuthFailed extends ProviderException
{
    public function code(): string
    {
        return 'provider_unavailable';
    }
}
