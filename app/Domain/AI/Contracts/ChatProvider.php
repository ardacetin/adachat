<?php

namespace App\Domain\AI\Contracts;

use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\ChatResult;
use App\Domain\AI\Data\Events\StreamEvent;
use App\Domain\AI\Exceptions\ProviderException;

/**
 * Ada's provider contract. Adapters know nothing about budgets, prices or
 * users; they turn a ChatRequest into normalized events and usage.
 */
interface ChatProvider
{
    /**
     * Yields TextDelta / ReasoningDelta / UsageReported events and ends with
     * exactly one Finished event.
     *
     * @return iterable<StreamEvent>
     *
     * @throws ProviderException before or during streaming
     */
    public function stream(ChatRequest $request, ?CancellationToken $cancellation = null): iterable;

    /**
     * @throws ProviderException
     */
    public function complete(ChatRequest $request): ChatResult;
}
