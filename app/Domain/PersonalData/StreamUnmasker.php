<?php

namespace App\Domain\PersonalData;

/**
 * Puts the masked values back into an answer as it streams. A placeholder
 * can arrive split over several chunks, so an unfinished "[…" at the end of
 * a chunk waits for the next one.
 */
final class StreamUnmasker
{
    private const LONGEST_PLACEHOLDER = 40;

    private string $pending = '';

    /**
     * @param  array<string, string>  $values  placeholder => original value
     */
    public function __construct(private readonly array $values) {}

    public function push(string $chunk): string
    {
        if ($this->values === []) {
            return $chunk;
        }

        $text = $this->pending.$chunk;
        $this->pending = '';
        $open = strrpos($text, '[');

        if ($open !== false && ! str_contains(substr($text, $open), ']') && strlen($text) - $open < self::LONGEST_PLACEHOLDER) {
            $this->pending = substr($text, $open);
            $text = substr($text, 0, $open);
        }

        return strtr($text, $this->values);
    }

    public function flush(): string
    {
        $text = strtr($this->pending, $this->values);
        $this->pending = '';

        return $text;
    }
}
