<?php

namespace App\Domain\AI\Data;

use App\Domain\AI\Enums\InputCountMethod;

final readonly class InputTokenCount
{
    public function __construct(
        public int $tokens,
        public InputCountMethod $method,
        public float $marginRatio,
    ) {}

    /**
     * Tokens to reserve budget for: the count plus the safety margin.
     */
    public function reservedTokens(): int
    {
        return (int) ceil($this->tokens * (1 + $this->marginRatio));
    }
}
