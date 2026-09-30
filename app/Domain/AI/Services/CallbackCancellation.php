<?php

namespace App\Domain\AI\Services;

use App\Domain\AI\Contracts\CancellationToken;
use Closure;

/**
 * Cancellation decided by a callback, e.g. connection_aborted() or a cache
 * flag set by the Stop button.
 */
final class CallbackCancellation implements CancellationToken
{
    /**
     * @param  Closure(): bool  $callback
     */
    public function __construct(private readonly Closure $callback) {}

    public function isCancelled(): bool
    {
        return ($this->callback)();
    }
}
