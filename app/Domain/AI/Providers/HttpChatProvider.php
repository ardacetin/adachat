<?php

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\CancellationToken;
use App\Domain\AI\Contracts\ChatProvider;
use App\Domain\AI\Contracts\InputTokenCounter;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\ChatResult;
use App\Domain\AI\Data\Events\Finished;
use App\Domain\AI\Data\Events\StreamEvent;
use App\Domain\AI\Data\Events\TextDelta;
use App\Domain\AI\Data\Events\UsageReported;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\FinishReason;
use App\Domain\AI\Exceptions\ProviderException;
use App\Domain\AI\Exceptions\ProviderUnavailable;
use App\Domain\AI\Http\ErrorMapper;
use App\Domain\AI\Http\SseEvent;
use App\Domain\AI\Http\SseParser;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Shared plumbing for direct-HTTP adapters: authenticated requests, error
 * mapping, SSE streaming with cancellation, and token counting built from
 * the same payload as generation.
 */
abstract class HttpChatProvider implements ChatProvider, InputTokenCounter
{
    public function __construct(
        protected readonly Http $http,
        protected readonly string $apiKey,
        protected readonly string $baseUrl,
        protected readonly int $timeoutSeconds = 300,
        protected readonly int $counterTimeoutSeconds = 3,
    ) {}

    abstract protected function name(): string;

    /**
     * @return array<string, string>
     */
    abstract protected function headers(): array;

    /**
     * Provider payload for generation. Counters derive their body from it.
     *
     * @return array<string, mixed>
     */
    abstract protected function payload(ChatRequest $request): array;

    abstract protected function streamUrl(ChatRequest $request): string;

    abstract protected function countUrl(ChatRequest $request): string;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    abstract protected function countPayload(ChatRequest $request, array $payload): array;

    /**
     * @param  array<string, mixed>  $body
     */
    abstract protected function countFromResponse(array $body): int;

    /**
     * Turn provider SSE events into normalized events. Must end by returning
     * the finish reason; usage is reported through $state.
     *
     * @param  Generator<int, SseEvent>  $events
     * @return Generator<int, StreamEvent, mixed, FinishReason>
     */
    abstract protected function translate(Generator $events, StreamState $state): Generator;

    public function stream(ChatRequest $request, ?CancellationToken $cancellation = null): Generator
    {
        $response = $this->send(
            $this->request($this->timeoutSeconds)->withOptions(['stream' => true]),
            $this->streamUrl($request),
            $this->payload($request),
        );

        $state = new StreamState($this->requestId($response));
        $events = SseParser::events($response->toPsrResponse()->getBody(), $cancellation);

        $translated = $this->translate($events, $state);
        yield from $translated;

        $reason = $cancellation?->isCancelled() ? FinishReason::Cancelled : $translated->getReturn();

        if ($state->usage !== null) {
            yield new UsageReported($state->usage);
        }

        yield new Finished($reason, $state->requestId);
    }

    public function complete(ChatRequest $request): ChatResult
    {
        $text = '';
        $usage = new TokenUsage;
        $reason = FinishReason::Error;
        $requestId = null;

        foreach ($this->stream($request) as $event) {
            match (true) {
                $event instanceof TextDelta => $text .= $event->text,
                $event instanceof UsageReported => $usage = $event->usage,
                $event instanceof Finished => [$reason, $requestId] = [$event->reason, $event->providerRequestId],
                default => null,
            };
        }

        return new ChatResult($text, $usage, $reason, $requestId);
    }

    public function count(ChatRequest $request): int
    {
        $response = $this->send(
            $this->request($this->counterTimeoutSeconds),
            $this->countUrl($request),
            $this->countPayload($request, $this->payload($request)),
        );

        $body = $response->json();

        if (! is_array($body)) {
            throw new ProviderUnavailable($this->name().': invalid token count response');
        }

        return $this->countFromResponse($body);
    }

    /**
     * Cheap authenticated call (model listing) to validate credentials.
     *
     * @throws ProviderException
     */
    public function checkConnection(): void
    {
        try {
            $response = $this->request(10)->get($this->baseUrl.'/models');
        } catch (ConnectionException $exception) {
            throw ErrorMapper::fromConnection($this->name(), $exception);
        }

        if ($response->failed() || $response->redirect()) {
            throw ErrorMapper::fromResponse($this->name(), $response);
        }
    }

    protected function requestId(Response $response): ?string
    {
        return null;
    }

    /**
     * Redirects are not followed: Guzzle drops only Authorization and Cookie
     * on another host, so keys sent in x-api-key or x-goog-api-key would go
     * along. A provider answering with a redirect is refused instead.
     */
    private function request(int $timeout): PendingRequest
    {
        return $this->http
            ->withoutRedirecting()
            ->withHeaders($this->headers())
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout($timeout);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(PendingRequest $request, string $url, array $payload): Response
    {
        try {
            $response = $request->post($url, $payload);
        } catch (ConnectionException $exception) {
            throw ErrorMapper::fromConnection($this->name(), $exception);
        }

        if ($response->failed() || $response->redirect()) {
            throw ErrorMapper::fromResponse($this->name(), $response);
        }

        return $response;
    }
}
