<?php

namespace App\Domain\AI\Data;

/**
 * A PDF sent with a user message to a model that reads files natively,
 * base64-encoded.
 */
final readonly class DocumentPart
{
    public function __construct(
        public string $mime,
        public string $base64,
        public string $name,
        public int $tokenEstimate,
    ) {}

    public function dataUrl(): string
    {
        return "data:{$this->mime};base64,{$this->base64}";
    }
}
