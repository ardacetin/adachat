<?php

use App\Domain\AI\Exceptions\ProviderUnavailable;
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

test('each event is yielded as soon as it arrives', function () {
    // Like PHP's HTTP stream wrapper, read($n) blocks until $n bytes arrived
    // (here: consumes further network chunks). The parser must not ask for
    // more than it needs, or tokens would reach the user in bursts.
    $chunks = ["data: one\n\n", "data: two\n\n", "data: three\n\n"];

    $network = new class($chunks) extends Stream
    {
        public int $delivered = 0;

        private string $available = '';

        /** @param list<string> $chunks */
        public function __construct(private array $chunks)
        {
            parent::__construct(Utils::tryFopen('php://temp', 'r+'));
        }

        public function read($length): string
        {
            while (strlen($this->available) < $length && $this->chunks !== []) {
                $this->available .= array_shift($this->chunks);
                $this->delivered++;
            }

            $out = substr($this->available, 0, $length);
            $this->available = substr($this->available, strlen($out));

            return $out;
        }

        public function eof(): bool
        {
            return $this->available === '' && $this->chunks === [];
        }
    };

    $events = SseParser::events($network);

    expect($events->current()->data)->toBe('one')
        ->and($network->delivered)->toBe(1);

    $events->next();

    expect($events->current()->data)->toBe('two')
        ->and($network->delivered)->toBe(2);
});

test('a line delivered slowly stops at the deadline, not at its line break', function () {
    // One data line that keeps arriving byte by byte and never ends: before
    // the deadline check inside the line, nothing bounded this read.
    $body = new class extends Stream
    {
        public int $reads = 0;

        public function __construct()
        {
            parent::__construct(Utils::tryFopen('php://temp', 'r+'));
        }

        public function eof(): bool
        {
            return false;
        }

        public function read($length): string
        {
            $this->reads++;

            return 'x';
        }
    };

    $events = iterator_to_array(SseParser::events($body, new CallbackCancellation(fn () => $body->reads >= 50)), false);

    expect($events)->toBe([])
        ->and($body->reads)->toBe(50);
});

test('a line or an event too large for a chat stream is refused', function () {
    $line = Utils::streamFor('data: '.str_repeat('a', SseParser::MAX_LINE_BYTES)."\n\n");
    expect(fn () => iterator_to_array(SseParser::events($line)))->toThrow(ProviderUnavailable::class);

    $lines = str_repeat('data: '.str_repeat('a', 1024 * 1024 - 16)."\n", 5)."\n";
    expect(fn () => iterator_to_array(SseParser::events(Utils::streamFor($lines))))->toThrow(ProviderUnavailable::class);
});
