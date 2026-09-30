<?php

namespace App\Domain\AI\Counters;

use App\Domain\AI\Contracts\InputTokenCounter;
use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;

/**
 * Conservative heuristic, used only when the provider's token-count
 * endpoint is unavailable (and the institution allows estimation). Assumes
 * at most 2 UTF-8 bytes per token, which over-estimates for Turkish, English
 * and code; a large safety margin is applied on top.
 */
final class EstimatedInputTokenCounter implements InputTokenCounter
{
    private const BYTES_PER_TOKEN = 2;

    private const PER_MESSAGE_OVERHEAD = 8;

    public function count(ChatRequest $request): int
    {
        $bytes = strlen((string) $request->systemPrompt) + array_sum(array_map(
            static fn (ChatMessage $message): int => strlen($message->text),
            $request->messages,
        ));

        return (int) ceil($bytes / self::BYTES_PER_TOKEN)
            + self::PER_MESSAGE_OVERHEAD * (count($request->messages) + 1);
    }
}
