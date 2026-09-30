<?php

namespace App\Domain\Budget\Data;

use App\Domain\Budget\Money\Usd;

final readonly class ReservationSize
{
    public function __construct(
        /** Output cap to send to the provider. */
        public int $maxOutputTokens,
        /** Input cost (counted tokens + margin, full price) + maximum output cost. */
        public Usd $amount,
        /** True when the remaining budget lowered the output cap. */
        public bool $outputCapped,
    ) {}
}
