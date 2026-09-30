<?php

namespace App\Domain\AI\Data;

use App\Domain\AI\Enums\InputCountMethod;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

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
        // Exact decimal arithmetic: a float product such as 1000 × 1.05 must
        // not round up to an extra token.
        return BigDecimal::of($this->tokens)
            ->multipliedBy(BigDecimal::one()->plus($this->margin()))
            ->toScale(0, RoundingMode::Ceiling)
            ->toInt();
    }

    /**
     * The margin as stored with reservations (DECIMAL(5,4)), e.g. "0.0500".
     */
    public function margin(): BigDecimal
    {
        return BigDecimal::of(number_format($this->marginRatio, 4, '.', ''));
    }
}
