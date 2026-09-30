<?php

namespace App\Domain\AI\Services;

use App\Domain\AI\Enums\ProviderDriver;
use App\Domain\AI\Exceptions\ProviderAuthFailed;
use App\Domain\AI\Providers\Anthropic\AnthropicChatProvider;
use App\Domain\AI\Providers\Gemini\GeminiChatProvider;
use App\Domain\AI\Providers\HttpChatProvider;
use App\Domain\AI\Providers\OpenAI\OpenAIChatProvider;
use App\Models\AiModel;
use App\Models\Provider;
use Illuminate\Http\Client\Factory as Http;

/**
 * Builds the adapter for a model's provider with its decrypted credential.
 * Credentials live only in memory for the duration of the request.
 */
final class ProviderManager
{
    public function __construct(private readonly Http $http) {}

    public function forModel(AiModel $model): HttpChatProvider
    {
        return $this->forProvider($model->provider);
    }

    public function forProvider(Provider $provider): HttpChatProvider
    {
        $key = $this->apiKey($provider);

        $arguments = [
            $this->http,
            $key,
            $provider->baseUrl(),
            (int) config('ada.providers.timeout', 300),
            (int) config('ada.providers.counter_timeout', 3),
        ];

        return match ($provider->driver) {
            ProviderDriver::OpenAI => new OpenAIChatProvider(...$arguments),
            ProviderDriver::Anthropic => new AnthropicChatProvider(...$arguments),
            ProviderDriver::Gemini => new GeminiChatProvider(...$arguments),
        };
    }

    /**
     * Active database credential, otherwise the .env fallback.
     */
    private function apiKey(Provider $provider): string
    {
        $key = $provider->activeCredential->secret
            ?? config('ada.providers.env_keys.'.$provider->driver->value);

        if (! is_string($key) || $key === '') {
            throw new ProviderAuthFailed("{$provider->driver->value}: no API key configured");
        }

        return $key;
    }
}
