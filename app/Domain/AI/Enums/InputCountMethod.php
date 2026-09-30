<?php

namespace App\Domain\AI\Enums;

enum InputCountMethod: string
{
    /** Counted by the provider's own token-count endpoint. */
    case ProviderEndpoint = 'provider_endpoint';
    /** Heuristic fallback; never the primary path. */
    case Estimated = 'estimated';
}
