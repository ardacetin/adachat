<?php

namespace App\Domain\AI\Exceptions;

final class InvalidProviderRequest extends ProviderException
{
    public function code(): string
    {
        return 'invalid_request';
    }
}
