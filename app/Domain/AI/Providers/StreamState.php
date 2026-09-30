<?php

namespace App\Domain\AI\Providers;

use App\Domain\AI\Data\TokenUsage;

/**
 * Mutable state collected while translating one provider stream.
 */
final class StreamState
{
    public ?TokenUsage $usage = null;

    public function __construct(public ?string $requestId = null) {}

    public function addUsage(TokenUsage $usage): void
    {
        $this->usage = $this->usage === null ? $usage : $this->usage->merge($usage);
    }
}
