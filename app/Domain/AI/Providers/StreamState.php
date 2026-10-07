<?php

namespace App\Domain\AI\Providers;

use App\Domain\AI\Data\TokenUsage;

/**
 * Mutable state collected while translating one provider stream. A turn
 * the provider paused and Ada continued spans several requests: usage is
 * the sum of them.
 */
final class StreamState
{
    public ?TokenUsage $usage = null;

    /**
     * The assistant content of a turn the provider paused, to send back so
     * that it resumes; null when the turn was not paused.
     *
     * @var list<array<string, mixed>>|null
     */
    public ?array $continuation = null;

    /** Usage of the requests before the current one. */
    private ?TokenUsage $previous = null;

    /** Usage reported so far by the current request (reported in parts). */
    private ?TokenUsage $current = null;

    public function __construct(public ?string $requestId = null) {}

    public function addUsage(TokenUsage $usage): void
    {
        $this->current = $this->current === null ? $usage : $this->current->merge($usage);
        $this->usage = $this->previous === null ? $this->current : $this->previous->plus($this->current);
    }

    /**
     * The next request of the same turn starts.
     */
    public function nextRequest(): void
    {
        $this->previous = $this->usage;
        $this->current = null;
        $this->continuation = null;
    }
}
