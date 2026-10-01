<?php

namespace App\Domain\AI\Providers\Gemini;

use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\Events\ReasoningDelta;
use App\Domain\AI\Data\Events\TextDelta;
use App\Domain\AI\Data\ImagePart;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\FinishReason;
use App\Domain\AI\Enums\MessageRole;
use App\Domain\AI\Http\ErrorMapper;
use App\Domain\AI\Providers\HttpChatProvider;
use App\Domain\AI\Providers\StreamState;
use Generator;

/**
 * Gemini API (models/{model}:streamGenerateContent?alt=sse).
 *
 * Usage normalization: promptTokenCount includes cached content, which is
 * subtracted; thoughtsTokenCount is reported separately from
 * candidatesTokenCount.
 */
final class GeminiChatProvider extends HttpChatProvider
{
    protected function name(): string
    {
        return 'gemini';
    }

    protected function headers(): array
    {
        return ['x-goog-api-key' => $this->apiKey];
    }

    protected function payload(ChatRequest $request): array
    {
        $payload = [
            'contents' => array_map(
                static fn (ChatMessage $message): array => [
                    'role' => $message->role === MessageRole::Assistant ? 'model' : 'user',
                    'parts' => self::parts($message),
                ],
                $request->messages,
            ),
            'generationConfig' => array_filter([
                'maxOutputTokens' => $request->maxOutputTokens,
                'temperature' => $request->temperature,
            ], static fn (mixed $value): bool => $value !== null),
        ];

        if ($request->systemPrompt !== null) {
            $payload['systemInstruction'] = ['parts' => [['text' => $request->systemPrompt]]];
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function parts(ChatMessage $message): array
    {
        $parts = array_map(
            static fn (ImagePart $image): array => ['inline_data' => ['mime_type' => $image->mime, 'data' => $image->base64]],
            $message->parts,
        );

        if ($message->text !== '' || $parts === []) {
            $parts[] = ['text' => $message->text];
        }

        return $parts;
    }

    protected function streamUrl(ChatRequest $request): string
    {
        return $this->modelUrl($request).':streamGenerateContent?alt=sse';
    }

    protected function countUrl(ChatRequest $request): string
    {
        return $this->modelUrl($request).':countTokens';
    }

    protected function countPayload(ChatRequest $request, array $payload): array
    {
        return [
            'generateContentRequest' => array_filter([
                'model' => 'models/'.$request->model,
                'contents' => $payload['contents'],
                'systemInstruction' => $payload['systemInstruction'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    protected function countFromResponse(array $body): int
    {
        return (int) ($body['totalTokens'] ?? 0);
    }

    protected function translate(Generator $events, StreamState $state): Generator
    {
        $reason = FinishReason::Error;

        foreach ($events as $event) {
            $data = $event->json() ?? [];

            if (isset($data['error'])) {
                $error = is_array($data['error']) ? $data['error'] : [];
                throw ErrorMapper::fromStreamError('gemini', $error['status'] ?? null, $error['message'] ?? null);
            }

            $state->requestId ??= is_string($data['responseId'] ?? null) ? $data['responseId'] : null;

            if (isset($data['promptFeedback']['blockReason'])) {
                $reason = FinishReason::ContentFilter;
            }

            $candidate = $data['candidates'][0] ?? [];

            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (isset($part['text']) && is_string($part['text'])) {
                    yield ($part['thought'] ?? false) === true
                        ? new ReasoningDelta($part['text'])
                        : new TextDelta($part['text']);
                }
            }

            if (isset($candidate['finishReason'])) {
                $reason = match ($candidate['finishReason']) {
                    'STOP' => FinishReason::Stop,
                    'MAX_TOKENS' => FinishReason::Length,
                    'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII' => FinishReason::ContentFilter,
                    default => FinishReason::Error,
                };
            }

            if (is_array($data['usageMetadata'] ?? null)) {
                $usage = $data['usageMetadata'];
                $prompt = (int) ($usage['promptTokenCount'] ?? 0);
                $cached = (int) ($usage['cachedContentTokenCount'] ?? 0);

                $state->addUsage(new TokenUsage(
                    input: max(0, $prompt - $cached),
                    cachedInput: $cached,
                    output: (int) ($usage['candidatesTokenCount'] ?? 0),
                    reasoning: (int) ($usage['thoughtsTokenCount'] ?? 0),
                ));
            }
        }

        return $reason;
    }

    private function modelUrl(ChatRequest $request): string
    {
        return $this->baseUrl.'/models/'.rawurlencode($request->model);
    }
}
