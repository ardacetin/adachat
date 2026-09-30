<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;
use Throwable;

final class IdentityRejected extends RuntimeException
{
    public function __construct(
        public readonly RejectionReason $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Sign-in rejected: {$reason->value}", previous: $previous);
    }
}
