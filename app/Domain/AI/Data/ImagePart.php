<?php

namespace App\Domain\AI\Data;

/**
 * An image sent with a user message, base64-encoded as every provider
 * accepts it inline.
 */
final readonly class ImagePart
{
    public function __construct(
        public string $mime,
        public string $base64,
        public int $tokenEstimate,
    ) {}

    public function dataUrl(): string
    {
        return "data:{$this->mime};base64,{$this->base64}";
    }
}
