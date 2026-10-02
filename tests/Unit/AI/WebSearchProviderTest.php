<?php

use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\Events\Finished;
use App\Domain\AI\Data\Events\SourceFound;
use App\Domain\AI\Data\Events\TextDelta;
use App\Domain\AI\Data\Events\UsageReported;
use App\Domain\AI\Data\Events\WebSearchStarted;
use App\Domain\AI\Enums\FinishReason;
use App\Domain\AI\Providers\Anthropic\AnthropicChatProvider;
use App\Domain\AI\Providers\Gemini\GeminiChatProvider;
use App\Domain\AI\Providers\OpenAI\OpenAIChatProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

dataset('search providers', [
    'anthropic' => [[
        'class' => AnthropicChatProvider::class,
        'fixture' => 'anthropic',
        'base' => 'https://api.anthropic.com/v1',
        'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3]],
        'counted' => fn (array $body) => $body['tools'] ?? null,
        'queries' => ['ada lovelace doğum tarihi'],
        'cited' => [['https://tr.wikipedia.org/wiki/Ada_Lovelace', 'Ada Lovelace - Vikipedi']],
        'results' => [['https://tr.wikipedia.org/wiki/Ada_Lovelace', 'Ada Lovelace - Vikipedi'], ['https://example.org/ada', null]],
        'searches' => 1,
        'input' => 4500,
    ]],
    'openai' => [[
        'class' => OpenAIChatProvider::class,
        'fixture' => 'openai',
        'base' => 'https://api.openai.com/v1',
        'tools' => [['type' => 'web_search']],
        'counted' => fn (array $body) => $body['tools'] ?? null,
        'queries' => ['ada lovelace doğum tarihi'],
        'cited' => [['https://tr.wikipedia.org/wiki/Ada_Lovelace', 'Ada Lovelace - Vikipedi']],
        'results' => [],
        // Opening a page is not a billed search.
        'searches' => 1,
        'input' => 3000,
    ]],
    'gemini' => [[
        'class' => GeminiChatProvider::class,
        'fixture' => 'gemini',
        'base' => 'https://generativelanguage.googleapis.com/v1beta',
        'tools' => [['googleSearch' => []]],
        'counted' => fn (array $body) => $body['generateContentRequest']['tools'] ?? null,
        'queries' => ['ada lovelace doğum tarihi', 'ada lovelace kimdir'],
        'cited' => [
            ['https://vertexaisearch.cloud.google.com/grounding-api-redirect/abc', 'wikipedia.org'],
            ['https://vertexaisearch.cloud.google.com/grounding-api-redirect/def', null],
        ],
        'results' => [],
        // Each unique, non-empty query is billed once.
        'searches' => 2,
        'input' => 800,
    ]],
]);

function searchRequest(?int $maxUses = 3): ChatRequest
{
    return new ChatRequest(
        model: 'test-model',
        messages: [ChatMessage::user('Ada Lovelace ne zaman doğdu?')],
        maxOutputTokens: 512,
        webSearchMaxUses: $maxUses,
    );
}

function fakeSearchStream(array $provider): void
{
    Http::fake(['*' => Http::response(
        file_get_contents(base_path("tests/Fixtures/providers/{$provider['fixture']}-web-search.sse")),
        200,
        ['Content-Type' => 'text/event-stream'],
    )]);
}

test('web search adds the provider tool to the request', function (array $provider) {
    fakeSearchStream($provider);

    iterator_to_array((new $provider['class'](app(Factory::class), 'sk-test', $provider['base']))->stream(searchRequest()), false);

    Http::assertSent(function (Request $request) use ($provider) {
        $body = json_decode($request->body(), true);

        expect($body['tools'])->toBe($provider['tools']);

        if ($provider['fixture'] === 'openai') {
            expect($body['max_tool_calls'])->toBe(3);
        }

        return true;
    });
})->with('search providers');

test('requests without web search carry no tools', function (array $provider) {
    fakeSearchStream($provider);

    iterator_to_array((new $provider['class'](app(Factory::class), 'sk-test', $provider['base']))->stream(searchRequest(null)), false);

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        expect($body)->not->toHaveKey('tools')->not->toHaveKey('max_tool_calls');

        return true;
    });
})->with('search providers');

test('token counting includes the search tool', function (array $provider) {
    Http::fake(['*' => Http::response(['input_tokens' => 10, 'totalTokens' => 10])]);

    (new $provider['class'](app(Factory::class), 'sk-test', $provider['base']))->count(searchRequest());

    Http::assertSent(function (Request $request) use ($provider) {
        expect($provider['counted'](json_decode($request->body(), true)))->toBe($provider['tools']);

        return true;
    });
})->with('search providers');

test('searches, sources and billed search counts are reported', function (array $provider) {
    fakeSearchStream($provider);

    $events = iterator_to_array((new $provider['class'](app(Factory::class), 'sk-test', $provider['base']))->stream(searchRequest()), false);

    $queries = array_map(fn (WebSearchStarted $e) => $e->query, array_values(array_filter($events, fn ($e) => $e instanceof WebSearchStarted)));
    $sources = array_values(array_filter($events, fn ($e) => $e instanceof SourceFound));
    $pairs = fn (bool $cited) => array_values(array_map(
        fn (SourceFound $s) => [$s->url, $s->title],
        array_filter($sources, fn (SourceFound $s) => $s->cited === $cited),
    ));
    $usage = array_values(array_filter($events, fn ($e) => $e instanceof UsageReported))[0]->usage;
    $finished = end($events);

    expect($queries)->toBe($provider['queries'])
        ->and($pairs(true))->toBe($provider['cited'])
        ->and($pairs(false))->toBe($provider['results'])
        ->and($usage->webSearches)->toBe($provider['searches'])
        ->and($usage->input)->toBe($provider['input'])
        ->and($finished)->toBeInstanceOf(Finished::class)
        ->and($finished->reason)->toBe(FinishReason::Stop);

    $text = implode('', array_map(fn ($e) => $e->text, array_filter($events, fn ($e) => $e instanceof TextDelta)));
    expect($text)->toEndWith("1815'te doğdu.");
})->with('search providers');

test('an Anthropic turn paused during searches ends as cut off', function () {
    Http::fake(['*' => Http::response(implode("\n", [
        'event: message_start',
        'data: {"type":"message_start","message":{"id":"msg_p","usage":{"input_tokens":10,"output_tokens":1}}}',
        '',
        'event: message_delta',
        'data: {"type":"message_delta","delta":{"stop_reason":"pause_turn"},"usage":{"output_tokens":5,"server_tool_use":{"web_search_requests":5}}}',
        '',
    ])."\n", 200, ['Content-Type' => 'text/event-stream'])]);

    $events = iterator_to_array((new AnthropicChatProvider(app(Factory::class), 'sk-test', 'https://api.anthropic.com/v1'))->stream(searchRequest(5)), false);

    expect(end($events)->reason)->toBe(FinishReason::Length)
        ->and(array_values(array_filter($events, fn ($e) => $e instanceof UsageReported))[0]->usage->webSearches)->toBe(5);
});
