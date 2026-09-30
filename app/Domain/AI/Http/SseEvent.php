<?php

namespace App\Domain\AI\Http;

final readonly class SseEvent
{
    public function __construct(
        public ?string $event,
        public string $data,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->data, true);

        return is_array($decoded) ? $decoded : null;
    }
}
