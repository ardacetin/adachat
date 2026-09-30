<?php

namespace App\Domain\AI\Data;

/**
 * Normalized token usage. All fields are DISJOINT: adapters subtract
 * provider-specific subsets (e.g. OpenAI reports cached tokens inside the
 * input count) so that costs can be summed field by field.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $input = 0,
        public int $cachedInput = 0,
        public int $cacheWrite = 0,
        public int $output = 0,
        public int $reasoning = 0,
    ) {}

    /**
     * Keep the larger value per field (usage may be reported in parts).
     */
    public function merge(self $other): self
    {
        return new self(
            max($this->input, $other->input),
            max($this->cachedInput, $other->cachedInput),
            max($this->cacheWrite, $other->cacheWrite),
            max($this->output, $other->output),
            max($this->reasoning, $other->reasoning),
        );
    }

    public function totalInput(): int
    {
        return $this->input + $this->cachedInput + $this->cacheWrite;
    }

    public function totalOutput(): int
    {
        return $this->output + $this->reasoning;
    }
}
