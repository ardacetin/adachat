<?php

/*
 * Opt-in checks against the real provider APIs. Skipped unless
 * ADA_LIVE_PROVIDER_TESTS=1 and the provider's key is in the environment
 * (OPENAI_API_KEY, ANTHROPIC_API_KEY, GEMINI_API_KEY). Each run spends a
 * handful of tokens. Model ids can be overridden per driver, e.g.
 * ADA_LIVE_OPENAI_MODEL=gpt-5-mini.
 */

use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\Events\Finished;
use App\Domain\AI\Data\Events\TextDelta;
use App\Domain\AI\Data\Events\UsageReported;
use App\Domain\AI\Enums\FinishReason;
use App\Domain\AI\Enums\ProviderDriver;
use App\Domain\AI\Services\ProviderManager;
use App\Models\AiModel;
use App\Models\Provider;

test('a real provider streams, counts and reports usage', function (ProviderDriver $driver, string $defaultModel) {
    if (! env('ADA_LIVE_PROVIDER_TESTS')) {
        $this->markTestSkipped('Set ADA_LIVE_PROVIDER_TESTS=1 to run live provider tests.');
    }

    if (blank(config("ada.providers.env_keys.{$driver->value}"))) {
        $this->markTestSkipped("No {$driver->envKey()} in the environment.");
    }

    $model = AiModel::factory()
        ->for(Provider::factory()->driver($driver))
        ->create(['provider_model_id' => env('ADA_LIVE_'.strtoupper($driver->value).'_MODEL', $defaultModel)]);

    $provider = app(ProviderManager::class)->forModel($model);
    $request = new ChatRequest(
        model: $model->provider_model_id,
        messages: [ChatMessage::user('Reply with the single word: ready')],
        maxOutputTokens: 256,
    );

    $counted = $provider->count($request);

    $text = '';
    $usage = null;
    $finished = null;

    foreach ($provider->stream($request) as $event) {
        match (true) {
            $event instanceof TextDelta => $text .= $event->text,
            $event instanceof UsageReported => $usage = $event->usage,
            $event instanceof Finished => $finished = $event,
            default => null,
        };
    }

    expect($counted)->toBeGreaterThan(0)
        ->and($text)->not->toBe('')
        ->and($usage)->not->toBeNull()
        ->and($usage->input + $usage->cachedInput + $usage->cacheWrite)->toBeGreaterThan(0)
        ->and($usage->output + $usage->reasoning)->toBeLessThanOrEqual(256)
        ->and($finished?->reason)->toBeIn([FinishReason::Stop, FinishReason::Length]);
})->with([
    'openai' => [ProviderDriver::OpenAI, 'gpt-5-mini'],
    'anthropic' => [ProviderDriver::Anthropic, 'claude-haiku-4-5'],
    'gemini' => [ProviderDriver::Gemini, 'gemini-2.5-flash'],
])->group('live');
