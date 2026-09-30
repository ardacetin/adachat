<?php

namespace App\Domain\AI\Http;

use App\Domain\AI\Contracts\CancellationToken;
use Generator;
use Psr\Http\Message\StreamInterface;

/**
 * Minimal Server-Sent Events parser (WHATWG event stream format) that reads
 * a PSR-7 stream incrementally. Reading stops — and the connection is
 * closed — as soon as the cancellation token fires.
 */
final class SseParser
{
    private const CHUNK_SIZE = 8192;

    /**
     * @return Generator<int, SseEvent>
     */
    public static function events(StreamInterface $body, ?CancellationToken $cancellation = null): Generator
    {
        $buffer = '';
        $event = null;
        $data = [];

        try {
            while (! $body->eof()) {
                if ($cancellation?->isCancelled()) {
                    return;
                }

                $chunk = $body->read(self::CHUNK_SIZE);

                if ($chunk === '') {
                    continue;
                }

                $buffer .= $chunk;

                while (($position = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $position), "\r");
                    $buffer = substr($buffer, $position + 1);

                    if ($line === '') {
                        if ($data !== []) {
                            // Stop promptly even when one read delivered many events.
                            if ($cancellation?->isCancelled()) {
                                return;
                            }

                            yield new SseEvent($event, implode("\n", $data));
                        }

                        $event = null;
                        $data = [];

                        continue;
                    }

                    if (str_starts_with($line, ':')) {
                        continue; // comment / keep-alive
                    }

                    [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
                    $value = str_starts_with($value, ' ') ? substr($value, 1) : $value;

                    match ($field) {
                        'event' => $event = $value,
                        'data' => $data[] = $value,
                        default => null, // id, retry: not needed
                    };
                }
            }

            // A final event without a trailing blank line.
            $line = rtrim($buffer, "\r\n");

            if (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5), ' ');
            }

            if ($data !== []) {
                yield new SseEvent($event, implode("\n", $data));
            }
        } finally {
            $body->close();
        }
    }
}
