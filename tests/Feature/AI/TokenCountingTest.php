<?php

use App\Domain\AI\Counters\EstimatedInputTokenCounter;
use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\ImagePart;
use App\Domain\AI\Enums\InputCountMethod;
use App\Domain\AI\Enums\MessageRole;
use App\Domain\AI\Enums\ProviderDriver;
use App\Domain\AI\Exceptions\TokenCountUnavailable;
use App\Domain\AI\Services\CredentialVault;
use App\Domain\AI\Services\TokenCounting;
use App\Models\AiModel;
use App\Models\Provider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function countingModel(ProviderDriver $driver): AiModel
{
    $provider = Provider::factory()->driver($driver)->create();
    app(CredentialVault::class)->rotate($provider, 'sk-test-0000');

    return AiModel::factory()->for($provider)->create(['provider_model_id' => 'test-model']);
}

function countingRequest(): ChatRequest
{
    return new ChatRequest('test-model', [ChatMessage::user(str_repeat('ğüşiöç ', 100))], 1024, 'You are Ada.');
}

test('the provider endpoint count is used with the provider margin', function (ProviderDriver $driver, array $body, float $margin) {
    Http::fake(['*' => Http::response($body)]);

    $count = app(TokenCounting::class)->count(countingModel($driver), countingRequest());

    expect($count->tokens)->toBe(1000)
        ->and($count->method)->toBe(InputCountMethod::ProviderEndpoint)
        ->and($count->marginRatio)->toBe($margin)
        ->and($count->reservedTokens())->toBe((int) ceil(1000 * (1 + $margin)));
})->with([
    'openai' => [ProviderDriver::OpenAI, ['input_tokens' => 1000], 0.0],
    'anthropic' => [ProviderDriver::Anthropic, ['input_tokens' => 1000], 0.05],
    'gemini' => [ProviderDriver::Gemini, ['totalTokens' => 1000], 0.0],
]);

test('a failing counter falls back to the conservative estimate', function (Closure $failure) {
    Http::fake($failure);

    $count = app(TokenCounting::class)->count(countingModel(ProviderDriver::Anthropic), countingRequest());

    // "ğüşiöç " is 12 UTF-8 bytes: (1200 + 12 prompt bytes) / 2 + 8 × 2 overhead.
    expect($count->method)->toBe(InputCountMethod::Estimated)
        ->and($count->marginRatio)->toBe(0.5)
        ->and($count->tokens)->toBe(622)
        ->and($count->reservedTokens())->toBe(933);
})->with([
    'server error' => [fn () => fn () => Http::response(['error' => ['message' => 'boom']], 500)],
    'rate limited' => [fn () => fn () => Http::response(['error' => ['message' => 'slow']], 429)],
    'timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
]);

test('the reject policy refuses instead of estimating', function () {
    config(['ada.budget.on_counter_failure' => 'reject']);
    Http::fake(['*' => Http::response([], 500)]);

    expect(fn () => app(TokenCounting::class)->count(countingModel(ProviderDriver::OpenAI), countingRequest()))
        ->toThrow(TokenCountUnavailable::class);
});

test('OpenAI-compatible providers are estimated without a count call', function () {
    config(['ada.budget.on_counter_failure' => 'reject']);
    Http::fake();

    $count = app(TokenCounting::class)->count(countingModel(ProviderDriver::OpenAICompatible), countingRequest());

    expect($count->method)->toBe(InputCountMethod::Estimated)
        ->and($count->marginRatio)->toBe(0.25)
        ->and($count->tokens)->toBe(622)
        ->and($count->reservedTokens())->toBe(778);

    Http::assertNothingSent();
});

test('the estimate includes the images of the request', function () {
    $request = new ChatRequest('test-model', [new ChatMessage(MessageRole::User, 'abcd', [
        new ImagePart('image/png', 'aW1n', 1600),
        new ImagePart('image/png', 'aW1n', 1600),
    ])], 1024);

    // 4 bytes / 2 + 2 × 8 overhead + 2 × 1600.
    expect((new EstimatedInputTokenCounter)->count($request))->toBe(3218);
});
