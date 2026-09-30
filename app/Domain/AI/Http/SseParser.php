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
    /**
     * @return Generator<int, SseEvent>
     */
    public static function events(StreamInterface $body, ?CancellationToken $cancellation = null): Generator
    {
        $event = null;
        $data = [];

        try {
            while (! $body->eof()) {
                if ($cancellation?->isCancelled()) {
                    return;
                }

                [$line, $complete] = self::readLine($body);

                if (! $complete) {
                    // A final event without a trailing blank line.
                    if (str_starts_with($line, 'data:')) {
                        $data[] = ltrim(substr($line, 5), ' ');
                    }

                    break;
                }

                if ($line === '') {
                    if ($data !== []) {
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

            if ($data !== [] && ! $cancellation?->isCancelled()) {
                yield new SseEvent($event, implode("\n", $data));
            }
        } finally {
            $body->close();
        }
    }

    /**
     * Read up to the next line break. Reading byte by byte (served from PHP's
     * stream buffer) returns each line as soon as it arrives; reading fixed
     * blocks would wait until a whole block is filled, so tokens would reach
     * the browser in bursts or only at the end of the answer.
     *
     * @return array{0: string, 1: bool} the line without its line break, and whether it was terminated
     */
    private static function readLine(StreamInterface $body): array
    {
        $line = '';

        while (! $body->eof()) {
            $byte = $body->read(1);

            if ($byte === '') {
                break;
            }

            if ($byte === "\n") {
                return [rtrim($line, "\r"), true];
            }

            $line .= $byte;
        }

        return [rtrim($line, "\r"), false];
    }
}
