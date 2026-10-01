<?php

use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\Events\Finished;
use App\Domain\AI\Data\Events\ReasoningDelta;
use App\Domain\AI\Data\Events\TextDelta;
use App\Domain\AI\Data\Events\UsageReported;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\FinishReason;
use App\Domain\AI\Exceptions\ContextLengthExceeded;
use App\Domain\AI\Exceptions\InvalidProviderRequest;
use App\Domain\AI\Exceptions\ProviderAuthFailed;
use App\Domain\AI\Exceptions\ProviderException;
use App\Domain\AI\Exceptions\ProviderOverloaded;
use App\Domain\AI\Exceptions\ProviderRateLimited;
use App\Domain\AI\Exceptions\ProviderTimeout;
use App\Domain\AI\Exceptions\ProviderUnavailable;
use App\Domain\AI\Providers\Anthropic\AnthropicChatProvider;
use App\Domain\AI\Providers\Gemini\GeminiChatProvider;
use App\Domain\AI\Providers\HttpChatProvider;
use App\Domain\AI\Providers\OpenAI\OpenAIChatProvider;
use App\Domain\AI\Providers\OpenAICompatible\OpenAICompatibleChatProvider;
use App\Domain\AI\Services\CallbackCancellation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const TEST_API_KEY = 'sk-test-secret-key-1234';

/**
 * Per-provider facts the shared contract is checked against.
 */
dataset('providers', [
    'openai' => [[
        'class' => OpenAIChatProvider::class,
        'fixture' => 'openai',
        'base' => 'https://api.openai.com/v1',
        'streamUrl' => 'https://api.openai.com/v1/responses',
        'countUrl' => 'https://api.openai.com/v1/responses/input_tokens',
        'countBody' => ['object' => 'response.input_tokens', 'input_tokens' => 321],
        'authHeader' => ['Authorization', 'Bearer '.TEST_API_KEY],
        'maxTokens' => fn (array $body) => $body['max_output_tokens'],
        'system' => fn (array $body) => $body['instructions'],
        'messages' => fn (array $body) => $body['input'],
        'usage' => new TokenUsage(input: 200, cachedInput: 1000, output: 50, reasoning: 250),
        'requestId' => 'req_openai',
        'reasoning' => false,
        'streamError' => ProviderRateLimited::class,
    ]],
    'anthropic' => [[
        'class' => AnthropicChatProvider::class,
        'fixture' => 'anthropic',
        'base' => 'https://api.anthropic.com/v1',
        'streamUrl' => 'https://api.anthropic.com/v1/messages',
        'countUrl' => 'https://api.anthropic.com/v1/messages/count_tokens',
        'countBody' => ['input_tokens' => 321],
        'authHeader' => ['x-api-key', TEST_API_KEY],
        'maxTokens' => fn (array $body) => $body['max_tokens'],
        'system' => fn (array $body) => $body['system'],
        'messages' => fn (array $body) => $body['messages'],
        'usage' => new TokenUsage(input: 25, cachedInput: 900, cacheWrite: 100, output: 42),
        'requestId' => 'req_anthropic',
        'reasoning' => true,
        'streamError' => ProviderOverloaded::class,
    ]],
    'gemini' => [[
        'class' => GeminiChatProvider::class,
        'fixture' => 'gemini',
        'base' => 'https://generativelanguage.googleapis.com/v1beta',
        'streamUrl' => 'https://generativelanguage.googleapis.com/v1beta/models/test-model:streamGenerateContent?alt=sse',
        'countUrl' => 'https://generativelanguage.googleapis.com/v1beta/models/test-model:countTokens',
        'countBody' => ['totalTokens' => 321],
        'authHeader' => ['x-goog-api-key', TEST_API_KEY],
        'maxTokens' => fn (array $body) => $body['generationConfig']['maxOutputTokens'],
        'system' => fn (array $body) => $body['systemInstruction']['parts'][0]['text'],
        'messages' => fn (array $body) => $body['contents'],
        'usage' => new TokenUsage(input: 200, cachedInput: 1000, output: 50, reasoning: 250),
        'requestId' => 'gem_1',
        'reasoning' => true,
        'streamError' => ProviderRateLimited::class,
    ]],
    'openai_compatible' => [[
        'class' => OpenAICompatibleChatProvider::class,
        'fixture' => 'openai_compatible',
        'base' => 'https://example.test/v1',
        'streamUrl' => 'https://example.test/v1/chat/completions',
        'countUrl' => null,
        'countBody' => null,
        'authHeader' => ['Authorization', 'Bearer '.TEST_API_KEY],
        'maxTokens' => fn (array $body) => $body['max_tokens'],
        'system' => fn (array $body) => $body['messages'][0]['content'],
        'messages' => fn (array $body) => array_slice($body['messages'], 1),
        'usage' => new TokenUsage(input: 200, cachedInput: 1000, output: 50, reasoning: 250),
        'requestId' => 'req_openai',
        'reasoning' => true,
        'streamError' => ProviderRateLimited::class,
    ]],
]);

function adapter(array $provider): HttpChatProvider
{
    return new $provider['class'](app(Factory::class), TEST_API_KEY, $provider['base'], 30, 3);
}

function chatRequest(): ChatRequest
{
    return new ChatRequest(
        model: 'test-model',
        messages: [ChatMessage::user('Merhaba'), ChatMessage::assistant('Selam!'), ChatMessage::user('Nasılsın?')],
        maxOutputTokens: 512,
        systemPrompt: 'You are Ada.',
    );
}

function fakeStream(array $provider, string $variant = 'stream'): void
{
    Http::fake(['*' => Http::response(
        file_get_contents(base_path("tests/Fixtures/providers/{$provider['fixture']}-{$variant}.sse")),
        200,
        ['Content-Type' => 'text/event-stream', 'x-request-id' => 'req_openai', 'request-id' => 'req_anthropic'],
    )]);
}

test('text is streamed in order and the stream ends with usage and finish', function (array $provider) {
    fakeStream($provider);

    $events = iterator_to_array(adapter($provider)->stream(chatRequest()), false);

    $text = implode('', array_map(fn ($e) => $e->text, array_filter($events, fn ($e) => $e instanceof TextDelta)));
    expect($text)->toBe('Merhaba dünya!');

    $last = array_pop($events);
    $usage = array_pop($events);

    expect($last)->toBeInstanceOf(Finished::class)
        ->and($last->reason)->toBe(FinishReason::Stop)
        ->and($last->providerRequestId)->toBe($provider['requestId'])
        ->and($usage)->toBeInstanceOf(UsageReported::class)
        ->and($usage->usage)->toEqual($provider['usage']);

    $hasReasoning = array_filter($events, fn ($e) => $e instanceof ReasoningDelta) !== [];
    expect($hasReasoning)->toBe($provider['reasoning']);
})->with('providers');

test('the output cap is reported as a length finish', function (array $provider) {
    fakeStream($provider, 'length');

    $result = adapter($provider)->complete(chatRequest());

    expect($result->finishReason)->toBe(FinishReason::Length)
        ->and($result->text)->toBe('Uzun bir')
        ->and($result->usage->totalOutput())->toBe(16);
})->with('providers');

test('the request carries the model, output cap, system prompt and credentials', function (array $provider) {
    fakeStream($provider);

    iterator_to_array(adapter($provider)->stream(chatRequest()));

    Http::assertSent(function (Request $request) use ($provider) {
        $body = $request->data();

        return $request->url() === $provider['streamUrl']
            && $request->hasHeader($provider['authHeader'][0], $provider['authHeader'][1])
            && $provider['maxTokens']($body) === 512
            && $provider['system']($body) === 'You are Ada.'
            && count($provider['messages']($body)) === 3;
    });
})->with('providers');

test('token counting sends the same messages as generation', function (array $provider) {
    if ($provider['countUrl'] === null) {
        Http::fake();

        expect(fn () => adapter($provider)->count(chatRequest()))->toThrow(ProviderUnavailable::class);
        Http::assertNothingSent();

        return;
    }

    Http::fake(['*' => Http::response($provider['countBody'])]);

    expect(adapter($provider)->count(chatRequest()))->toBe(321);

    Http::assertSent(function (Request $request) use ($provider) {
        $body = $request->data();
        $counted = $provider['fixture'] === 'gemini' ? $body['generateContentRequest'] : $body;

        return $request->url() === $provider['countUrl']
            && ! array_key_exists('stream', $body)
            && $provider['messages']($counted) === $provider['messages'](
                (fn () => $this->payload(chatRequest()))->call(adapter($provider)),
            );
    });
})->with('providers');

test('errors inside the stream are mapped', function (array $provider) {
    fakeStream($provider, 'stream-error');

    expect(fn () => iterator_to_array(adapter($provider)->stream(chatRequest())))
        ->toThrow($provider['streamError']);
})->with('providers');

test('HTTP errors are mapped without leaking the key', function (array $provider, int $status, array $body, string $expected) {
    Http::fake(['*' => Http::response($body, $status)]);

    try {
        iterator_to_array(adapter($provider)->stream(chatRequest()));
        $this->fail('Expected an exception.');
    } catch (ProviderException $exception) {
        expect($exception)->toBeInstanceOf($expected)
            ->and($exception->getMessage())->not->toContain(TEST_API_KEY)
            ->and($exception->status)->toBe($status);
    }
})->with('providers')->with([
    'unauthorized' => [401, ['error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], ProviderAuthFailed::class],
    'rate limited' => [429, ['error' => ['type' => 'rate_limit_error', 'message' => 'slow down']], ProviderRateLimited::class],
    'overloaded' => [529, ['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], ProviderOverloaded::class],
    'unavailable' => [503, ['error' => ['status' => 'UNAVAILABLE', 'message' => 'try later']], ProviderOverloaded::class],
    'context too long' => [400, ['error' => ['code' => 'context_length_exceeded', 'message' => 'prompt is too long: 250000 tokens > 200000 maximum']], ContextLengthExceeded::class],
    'bad request' => [400, ['error' => ['type' => 'invalid_request_error', 'message' => 'temperature out of range']], InvalidProviderRequest::class],
    'server error' => [500, ['error' => ['message' => 'internal']], ProviderUnavailable::class],
]);

test('timeouts are mapped', function (array $provider) {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 3001 milliseconds'));

    expect(fn () => iterator_to_array(adapter($provider)->stream(chatRequest())))
        ->toThrow(ProviderTimeout::class);
})->with('providers');

test('cancellation stops the stream and reports a cancelled finish', function (array $provider) {
    fakeStream($provider);
    $cancelled = false;
    $events = [];

    $stream = adapter($provider)->stream(chatRequest(), new CallbackCancellation(function () use (&$cancelled) {
        return $cancelled;
    }));

    foreach ($stream as $event) {
        $events[] = $event;

        if ($event instanceof TextDelta) {
            $cancelled = true;
        }
    }

    $texts = array_values(array_filter($events, fn ($e) => $e instanceof TextDelta));

    expect($texts)->toHaveCount(1)
        ->and(end($events))->toBeInstanceOf(Finished::class)
        ->and(end($events)->reason)->toBe(FinishReason::Cancelled);
})->with('providers');

test('OpenAI-compatible requests without a key send no Authorization header', function () {
    fakeStream(['fixture' => 'openai_compatible']);

    $adapter = new OpenAICompatibleChatProvider(app(Factory::class), '', 'http://localhost:11434/v1', 30, 3);
    $result = $adapter->complete(chatRequest());

    expect($result->text)->toBe('Merhaba dünya!');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:11434/v1/chat/completions'
        && ! $request->hasHeader('Authorization')
        && $request->data()['stream'] === true
        && $request->data()['stream_options'] === ['include_usage' => true]
        && $request->data()['messages'][0] === ['role' => 'system', 'content' => 'You are Ada.']);
});

test('an OpenAI-compatible stream without a finish reason ends on [DONE]', function () {
    Http::fake(['*' => Http::response(
        "data: {\"choices\":[{\"delta\":{\"content\":\"Tamam\"}}]}\n\ndata: [DONE]\n\n",
        200,
        ['Content-Type' => 'text/event-stream'],
    )]);

    $adapter = new OpenAICompatibleChatProvider(app(Factory::class), '', 'http://localhost:8000/v1', 30, 3);
    $result = $adapter->complete(chatRequest());

    expect($result->text)->toBe('Tamam')
        ->and($result->finishReason)->toBe(FinishReason::Stop);
});
