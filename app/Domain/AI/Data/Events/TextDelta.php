<?php

namespace App\Domain\AI\Data\Events;

final readonly class TextDelta implements StreamEvent
{
    public function __construct(public string $text) {}
}
