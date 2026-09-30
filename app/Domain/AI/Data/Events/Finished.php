<?php

namespace App\Domain\AI\Data\Events;

use App\Domain\AI\Enums\FinishReason;

/**
 * Always the last event of a stream.
 */
final readonly class Finished implements StreamEvent
{
    public function __construct(
        public FinishReason $reason,
        public ?string $providerRequestId = null,
    ) {}
}
