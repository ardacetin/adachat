<?php

namespace App\Domain\AI\Providers\Anthropic;

use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\Events\ReasoningDelta;
use App\Domain\AI\Data\Events\TextDelta;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\FinishReason;
use App\Domain\AI\Http\ErrorMapper;
use App\Domain\AI\Providers\HttpChatProvider;
use App\Domain\AI\Providers\StreamState;
use Generator;
use Illuminate\Http\Client\Response;

/**
 * Anthropic Messages API (POST /messages, stream: true).
 *
 * Usage normalization: input_tokens already excludes cache reads/writes,
 * which are reported separately. Thinking tokens are billed as output and
 * not reported separately, so reasoning stays 0.
 */
final class AnthropicChatProvider extends HttpChatProvider
{
    public const API_VERSION = '2023-06-01';

    protected function name(): string
    {
        return 'anthropic';
    }

    protected function headers(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
        ];
    }

    protected function payload(ChatRequest $request): array
    {
        return array_filter([
            'model' => $request->model,
            'system' => $request->systemPrompt,
            'messages' => array_map(
                static fn (ChatMessage $message): array => ['role' => $message->role->value, 'content' => $message->text],
                $request->messages,
            ),
            'max_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
            'stream' => true,
        ], static fn (mixed $value): bool => $value !== null);
    }

    protected function streamUrl(ChatRequest $request): string
    {
        return $this->baseUrl.'/messages';
    }

    protected function countUrl(ChatRequest $request): string
    {
        return $this->baseUrl.'/messages/count_tokens';
    }

    protected function countPayload(ChatRequest $request, array $payload): array
    {
        return array_intersect_key($payload, array_flip(['model', 'system', 'messages']));
    }

    protected function countFromResponse(array $body): int
    {
        return (int) ($body['input_tokens'] ?? 0);
    }

    protected function requestId(Response $response): ?string
    {
        return $response->header('request-id') ?: null;
    }

    protected function translate(Generator $events, StreamState $state): Generator
    {
        $reason = FinishReason::Error;

        foreach ($events as $event) {
            $data = $event->json() ?? [];

            switch ($event->event ?? ($data['type'] ?? null)) {
                case 'message_start':
                    $message = is_array($data['message'] ?? null) ? $data['message'] : [];
                    $state->requestId ??= is_string($message['id'] ?? null) ? $message['id'] : null;
                    $usage = $message['usage'] ?? [];
                    $state->addUsage(new TokenUsage(
                        input: (int) ($usage['input_tokens'] ?? 0),
                        cachedInput: (int) ($usage['cache_read_input_tokens'] ?? 0),
                        cacheWrite: (int) ($usage['cache_creation_input_tokens'] ?? 0),
                        output: (int) ($usage['output_tokens'] ?? 0),
                    ));
                    break;

                case 'content_block_delta':
                    $delta = $data['delta'] ?? [];
                    match ($delta['type'] ?? null) {
                        'text_delta' => yield new TextDelta((string) ($delta['text'] ?? '')),
                        'thinking_delta' => yield new ReasoningDelta((string) ($delta['thinking'] ?? '')),
                        default => null,
                    };
                    break;

                case 'message_delta':
                    // Cumulative output count for the whole message.
                    $state->addUsage(new TokenUsage(output: (int) ($data['usage']['output_tokens'] ?? 0)));
                    $reason = match ($data['delta']['stop_reason'] ?? null) {
                        'end_turn', 'stop_sequence', 'tool_use' => FinishReason::Stop,
                        'max_tokens' => FinishReason::Length,
                        'refusal' => FinishReason::ContentFilter,
                        default => $reason,
                    };
                    break;

                case 'error':
                    $error = $data['error'] ?? [];
                    throw ErrorMapper::fromStreamError('anthropic', $error['type'] ?? null, $error['message'] ?? null);
            }
        }

        return $reason;
    }
}
