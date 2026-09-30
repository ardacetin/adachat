<?php

namespace App\Domain\AI\Exceptions;

final class ContextLengthExceeded extends ProviderException
{
    public function code(): string
    {
        return 'context_too_long';
    }
}
