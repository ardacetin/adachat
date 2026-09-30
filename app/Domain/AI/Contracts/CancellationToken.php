<?php

namespace App\Domain\AI\Contracts;

/**
 * Checked by adapters between stream chunks (client abort, Stop button).
 */
interface CancellationToken
{
    public function isCancelled(): bool;
}
