<?php

namespace App\Domain\AI\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for provider failures. Messages are safe to log: they never
 * contain API keys, request bodies or prompts.
 */
abstract class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Stable, translatable error code (chat.errors.<code>).
     */
    abstract public function code(): string;

    /**
     * Whether retrying the same request later may succeed.
     */
    public function retryable(): bool
    {
        return false;
    }
}
