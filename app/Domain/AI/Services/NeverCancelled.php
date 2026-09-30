<?php

namespace App\Domain\AI\Services;

use App\Domain\AI\Contracts\CancellationToken;

final class NeverCancelled implements CancellationToken
{
    public function isCancelled(): bool
    {
        return false;
    }
}
