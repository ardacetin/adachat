<?php

namespace App\Domain\Attachments\Exceptions;

use RuntimeException;

/**
 * An upload that Ada does not accept. The reason is a key under
 * chat.attachments in lang/*, shown to the user.
 */
final class AttachmentRejected extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $replace = [],
    ) {
        parent::__construct($reason);
    }

    public function userMessage(): string
    {
        return __("chat.attachments.{$this->reason}", $this->replace);
    }
}
