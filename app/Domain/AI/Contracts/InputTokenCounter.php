<?php

namespace App\Domain\AI\Contracts;

use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Exceptions\ProviderException;

/**
 * Counts the input tokens of the exact request that will be sent.
 */
interface InputTokenCounter
{
    /**
     * @return int number of input tokens (without safety margin)
     *
     * @throws ProviderException
     */
    public function count(ChatRequest $request): int;
}
