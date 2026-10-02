<?php

namespace App\Domain\AI\Data\Events;

/**
 * A web page from a search: cited in the answer, or only among the results.
 */
final readonly class SourceFound implements StreamEvent
{
    public function __construct(
        public string $url,
        public ?string $title = null,
        public bool $cited = true,
    ) {}
}
