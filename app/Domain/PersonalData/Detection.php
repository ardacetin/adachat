<?php

namespace App\Domain\PersonalData;

/**
 * One piece of personal data found in a text: where it is (byte offsets)
 * and what kind it is. The value itself is never logged or stored.
 */
final readonly class Detection
{
    public function __construct(
        public string $kind,
        public int $offset,
        public string $value,
    ) {}

    public function end(): int
    {
        return $this->offset + strlen($this->value);
    }
}
