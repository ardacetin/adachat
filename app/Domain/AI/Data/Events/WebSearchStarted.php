<?php

namespace App\Domain\AI\Data\Events;

/**
 * The provider runs a web search. The query is what the model searched
 * for, when the provider reports it.
 */
final readonly class WebSearchStarted implements StreamEvent
{
    public function __construct(public ?string $query = null) {}
}
