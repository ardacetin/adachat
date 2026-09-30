<?php

namespace App\Domain\AI\Data\Events;

/**
 * Visible reasoning/thinking text, when a provider streams it. Not shown in V1.
 */
final readonly class ReasoningDelta implements StreamEvent
{
    public function __construct(public string $text) {}
}
