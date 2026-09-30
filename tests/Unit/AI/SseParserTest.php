<?php

use App\Domain\AI\Http\SseParser;
use App\Domain\AI\Services\CallbackCancellation;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * A stream that returns at most $size bytes per read, to exercise chunk
 * boundaries in the middle of lines and events.
 */
function chunkedStream(string $content, int $size): StreamInterface
{
    return new class($content, $size) extends Stream
    {
        public function __construct(string $content, private int $size)
        {
            parent::__construct(Utils::tryFopen('php://temp', 'r+'));
            $this->write($content);
            $this->rewind();
        }

        public function read($length): string
        {
            return parent::read(min($length, $this->size));
        }
    };
}

test('events, types and multi-line data are parsed', function () {
    $body = Utils::streamFor(": keep-alive\n\nevent: greeting\ndata: line one\ndata: line two\n\ndata: {\"a\":1}\n\n");

    $events = iterator_to_array(SseParser::events($body), false);

    expect($events)->toHaveCount(2)
        ->and($events[0]->event)->toBe('greeting')
        ->and($events[0]->data)->toBe("line one\nline two")
        ->and($events[1]->event)->toBeNull()
        ->and($events[1]->json())->toBe(['a' => 1]);
});

test('CRLF line endings and a missing final blank line are handled', function () {
    $events = iterator_to_array(SseParser::events(Utils::streamFor("data: first\r\n\r\ndata: last")), false);

    expect(array_map(fn ($e) => $e->data, $events))->toBe(['first', 'last']);
});

test('events split across reads are reassembled', function (int $size) {
    $content = "event: a\ndata: {\"text\":\"Merhaba dünya\"}\n\nevent: b\ndata: {\"n\":2}\n\n";

    $events = iterator_to_array(SseParser::events(chunkedStream($content, $size)), false);

    expect(array_map(fn ($e) => [$e->event, $e->json()], $events))->toBe([
        ['a', ['text' => 'Merhaba dünya']],
        ['b', ['n' => 2]],
    ]);
})->with([1, 3, 7, 64]);

test('cancellation stops parsing and closes the stream', function () {
    $body = Utils::streamFor("data: 1\n\ndata: 2\n\ndata: 3\n\n");
    $cancelled = false;
    $received = [];

    foreach (SseParser::events($body, new CallbackCancellation(function () use (&$cancelled) {
        return $cancelled;
    })) as $event) {
        $received[] = $event->data;
        $cancelled = true;
    }

    expect($received)->toBe(['1'])
        ->and($body->isReadable())->toBeFalse();
});
