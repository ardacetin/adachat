<?php

namespace App\Domain\Budget\Data;

use App\Domain\Budget\Money\Usd;

final readonly class Discrepancy
{
    public function __construct(
        public int $periodId,
        public int $userId,
        /** spent_usd or reserved_usd; institution_spent_usd / institution_reserved_usd for institution months (period id = institution_periods id, user 0) */
        public string $column,
        public Usd $stored,
        public Usd $expected,
    ) {}
}
