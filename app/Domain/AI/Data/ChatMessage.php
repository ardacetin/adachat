<?php

namespace App\Domain\AI\Data;

use App\Domain\AI\Enums\MessageRole;

final readonly class ChatMessage
{
    /**
     * @param  list<ImagePart|DocumentPart>  $parts  images and PDFs (user messages only); text files are part of $text
     */
    public function __construct(
        public MessageRole $role,
        public string $text,
        public array $parts = [],
    ) {}

    public static function user(string $text): self
    {
        return new self(MessageRole::User, $text);
    }

    public static function assistant(string $text): self
    {
        return new self(MessageRole::Assistant, $text);
    }

    public function hasParts(): bool
    {
        return $this->parts !== [];
    }
}
