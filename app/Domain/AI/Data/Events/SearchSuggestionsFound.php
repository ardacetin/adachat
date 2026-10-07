<?php

namespace App\Domain\AI\Data\Events;

/**
 * Google's Search Suggestions for an answer grounded in Google Search
 * (Gemini groundingMetadata.searchEntryPoint.renderedContent): HTML and CSS
 * that Google's terms require to be shown, unmodified, with the answer.
 */
final readonly class SearchSuggestionsFound implements StreamEvent
{
    public function __construct(public string $html) {}
}
