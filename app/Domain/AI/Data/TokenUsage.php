<?php

namespace App\Domain\AI\Data;

/**
 * Normalized token usage. All fields are DISJOINT: adapters subtract
 * provider-specific subsets (e.g. OpenAI reports cached tokens inside the
 * input count) so that costs can be summed field by field.
 */
final readonly class TokenUsage
{
    public int $input;

    public int $cachedInput;

    public int $cacheWrite;

    public int $output;

    public int $reasoning;

    /** Billable web searches the provider ran for the request. */
    public int $webSearches;

    /**
     * Negative values (a provider reporting a subset larger than its total,
     * or a malformed field) count as zero: usage never refunds a charge.
     */
    public function __construct(
        int $input = 0,
        int $cachedInput = 0,
        int $cacheWrite = 0,
        int $output = 0,
        int $reasoning = 0,
        int $webSearches = 0,
    ) {
        $this->input = max(0, $input);
        $this->cachedInput = max(0, $cachedInput);
        $this->cacheWrite = max(0, $cacheWrite);
        $this->output = max(0, $output);
        $this->reasoning = max(0, $reasoning);
        $this->webSearches = max(0, $webSearches);
    }

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
            max($this->webSearches, $other->webSearches),
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
