<?php

namespace App\Domain\Conversations\Data;

/**
 * One server-sent event of the chat protocol (docs/architecture.md §6):
 * message.started, delta, message.completed, error.
 */
final readonly class ChatStreamEvent
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $name,
        public array $data,
    ) {}

    public function encode(): string
    {
        return "event: {$this->name}\ndata: ".json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n\n";
    }
}
