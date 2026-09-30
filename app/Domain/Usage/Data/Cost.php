<?php

namespace App\Domain\Usage\Data;

use App\Domain\Budget\Money\Usd;

final readonly class Cost
{
    public function __construct(
        public Usd $input,
        public Usd $output,
        public Usd $other,
    ) {}

    public function total(): Usd
    {
        return $this->input->plus($this->output)->plus($this->other);
    }
}
