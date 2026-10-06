<?php

namespace App\Domain\AI\Http;

use App\Domain\AI\Contracts\CancellationToken;
use App\Domain\AI\Exceptions\ProviderUnavailable;
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
     * A line or an event this large is not a chat stream: the provider (or
     * something on the way) is misbehaving, and memory is not spent on it.
     */
    public const MAX_LINE_BYTES = 1024 * 1024;

    public const MAX_EVENT_BYTES = 4 * 1024 * 1024;

    /**
     * @return Generator<int, SseEvent>
     */
    public static function events(StreamInterface $body, ?CancellationToken $cancellation = null): Generator
    {
        $event = null;
        $data = [];
        $size = 0;

        try {
            while (! $body->eof()) {
                if ($cancellation?->isCancelled()) {
                    return;
                }

                [$line, $complete] = self::readLine($body, $cancellation);

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
                    $size = 0;

                    continue;
                }

                if (str_starts_with($line, ':')) {
                    continue; // comment / keep-alive
                }

                [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
                $value = str_starts_with($value, ' ') ? substr($value, 1) : $value;

                match ($field) {
                    'event' => $event = $value,
                    'data' => $data[] = self::counted($value, $size),
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

    private static function counted(string $value, int &$size): string
    {
        $size += strlen($value) + 1;

        if ($size > self::MAX_EVENT_BYTES) {
            throw new ProviderUnavailable('Provider stream event exceeds '.self::MAX_EVENT_BYTES.' bytes');
        }

        return $value;
    }

    /**
     * Read up to the next line break. Reading byte by byte (served from PHP's
     * stream buffer) returns each line as soon as it arrives; reading fixed
     * blocks would wait until a whole block is filled, so tokens would reach
     * the browser in bursts or only at the end of the answer. The token is
     * checked inside the line too: a line delivered slowly must not hold the
     * request past its deadline.
     *
     * @return array{0: string, 1: bool} the line without its line break, and whether it was terminated
     */
    private static function readLine(StreamInterface $body, ?CancellationToken $cancellation): array
    {
        $line = '';

        while (! $body->eof()) {
            if ($cancellation?->isCancelled()) {
                break;
            }

            $byte = $body->read(1);

            if ($byte === '') {
                break;
            }

            if ($byte === "\n") {
                return [rtrim($line, "\r"), true];
            }

            $line .= $byte;

            if (strlen($line) > self::MAX_LINE_BYTES) {
                throw new ProviderUnavailable('Provider stream line exceeds '.self::MAX_LINE_BYTES.' bytes');
            }
        }

        return [rtrim($line, "\r"), false];
    }
}
