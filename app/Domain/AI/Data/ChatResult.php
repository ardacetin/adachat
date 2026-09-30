<?php

namespace App\Domain\AI\Data;

use App\Domain\AI\Enums\FinishReason;

final readonly class ChatResult
{
    public function __construct(
        public string $text,
        public TokenUsage $usage,
        public FinishReason $finishReason,
        public ?string $providerRequestId = null,
    ) {}
}
