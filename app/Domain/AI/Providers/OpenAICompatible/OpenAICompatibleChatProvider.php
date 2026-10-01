<?php

namespace App\Domain\AI\Providers\OpenAICompatible;

use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\Events\ReasoningDelta;
use App\Domain\AI\Data\Events\TextDelta;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\FinishReason;
use App\Domain\AI\Exceptions\ProviderUnavailable;
use App\Domain\AI\Http\ErrorMapper;
use App\Domain\AI\Providers\HttpChatProvider;
use App\Domain\AI\Providers\StreamState;
use Generator;
use Illuminate\Http\Client\Response;

/**
 * OpenAI Chat Completions (POST /chat/completions, stream: true), the
 * dialect most gateways and local servers speak: OpenRouter, Ollama, vLLM,
 * Groq, LM Studio. The API key is optional (local servers).
 *
 * There is no token count endpoint: input is estimated before the request
 * (TokenCounting) and settled with the usage reported in the last chunk
 * (stream_options.include_usage). Usage normalization as for OpenAI:
 * prompt_tokens includes cached tokens, completion_tokens reasoning tokens.
 */
final class OpenAICompatibleChatProvider extends HttpChatProvider
{
    protected function name(): string
    {
        return 'openai_compatible';
    }

    protected function headers(): array
    {
        return $this->apiKey === '' ? [] : ['Authorization' => 'Bearer '.$this->apiKey];
    }

    protected function payload(ChatRequest $request): array
    {
        $messages = array_map(
            static fn (ChatMessage $message): array => ['role' => $message->role->value, 'content' => $message->text],
            $request->messages,
        );

        if ($request->systemPrompt !== null && $request->systemPrompt !== '') {
            array_unshift($messages, ['role' => 'system', 'content' => $request->systemPrompt]);
        }

        return array_filter([
            'model' => $request->model,
            'messages' => $messages,
            'max_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ], static fn (mixed $value): bool => $value !== null);
    }

    protected function streamUrl(ChatRequest $request): string
    {
        return $this->baseUrl.'/chat/completions';
    }

    public function count(ChatRequest $request): int
    {
        throw new ProviderUnavailable('openai_compatible: no token count endpoint');
    }

    protected function countUrl(ChatRequest $request): string
    {
        return $this->baseUrl.'/chat/completions';
    }

    protected function countPayload(ChatRequest $request, array $payload): array
    {
        return $payload;
    }

    protected function countFromResponse(array $body): int
    {
        return 0;
    }

    protected function requestId(Response $response): ?string
    {
        return $response->header('x-request-id') ?: null;
    }

    protected function translate(Generator $events, StreamState $state): Generator
    {
        $reason = null;
        $done = false;

        foreach ($events as $event) {
            if (trim($event->data) === '[DONE]') {
                $done = true;

                continue;
            }

            $data = $event->json();

            if (! is_array($data)) {
                continue;
            }

            if (is_array($data['error'] ?? null)) {
                $error = $data['error'];

                throw ErrorMapper::fromStreamError(
                    'openai_compatible',
                    self::errorType($error),
                    is_string($error['message'] ?? null) ? $error['message'] : null,
                );
            }

            $state->requestId ??= is_string($data['id'] ?? null) ? $data['id'] : null;

            $choice = $data['choices'][0] ?? null;

            if (is_array($choice)) {
                $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

                // Reasoning models: DeepSeek/vLLM use reasoning_content, OpenRouter reasoning.
                $reasoning = $delta['reasoning_content'] ?? $delta['reasoning'] ?? null;

                if (is_string($reasoning) && $reasoning !== '') {
                    yield new ReasoningDelta($reasoning);
                }

                if (is_string($delta['content'] ?? null) && $delta['content'] !== '') {
                    yield new TextDelta($delta['content']);
                }

                if (is_string($choice['finish_reason'] ?? null)) {
                    $reason = match ($choice['finish_reason']) {
                        'length' => FinishReason::Length,
                        'content_filter' => FinishReason::ContentFilter,
                        default => FinishReason::Stop,
                    };
                }
            }

            if (is_array($data['usage'] ?? null)) {
                $state->addUsage(self::usage($data['usage']));
            }
        }

        // Some servers end with [DONE] but never send a finish reason.
        return $reason ?? ($done ? FinishReason::Stop : FinishReason::Error);
    }

    /**
     * The error type as the mapper understands it. Gateways send a string
     * type or code, or an HTTP status as a number (OpenRouter: 429, 502…).
     *
     * @param  array<mixed>  $error
     */
    private static function errorType(array $error): ?string
    {
        foreach (['type', 'code'] as $key) {
            if (is_string($error[$key] ?? null) && $error[$key] !== '') {
                return $error[$key];
            }
        }

        return match ($error['code'] ?? null) {
            429 => 'rate_limit',
            502, 503, 529 => 'overloaded',
            default => null,
        };
    }

    /**
     * @param  array<mixed>  $usage
     */
    private static function usage(array $usage): TokenUsage
    {
        $input = (int) ($usage['prompt_tokens'] ?? 0);
        $cached = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
        $output = (int) ($usage['completion_tokens'] ?? 0);
        $reasoning = (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0);

        return new TokenUsage(
            input: max(0, $input - $cached),
            cachedInput: $cached,
            output: max(0, $output - $reasoning),
            reasoning: $reasoning,
        );
    }
}
