<?php

namespace App\Domain\AI\Data;

use App\Domain\AI\Enums\MessageRole;

final readonly class ChatMessage
{
    public function __construct(
        public MessageRole $role,
        public string $text,
    ) {}

    public static function user(string $text): self
    {
        return new self(MessageRole::User, $text);
    }

    public static function assistant(string $text): self
    {
        return new self(MessageRole::Assistant, $text);
    }
}
