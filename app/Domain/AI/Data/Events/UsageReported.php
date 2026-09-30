<?php

namespace App\Domain\AI\Data\Events;

use App\Domain\AI\Data\TokenUsage;

final readonly class UsageReported implements StreamEvent
{
    public function __construct(public TokenUsage $usage) {}
}
