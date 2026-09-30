<?php

namespace App\Domain\Conversations\Exceptions;

use RuntimeException;

/**
 * A request refused before anything was generated or charged.
 */
final class ChatRefused extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($errorCode);
    }
}
