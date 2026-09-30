<?php

namespace App\Domain\AI\Exceptions;

final class ContentFiltered extends ProviderException
{
    public function code(): string
    {
        return 'content_filtered';
    }
}
