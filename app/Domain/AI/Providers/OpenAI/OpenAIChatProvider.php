<?php

namespace App\Domain\AI\Providers\OpenAI;

use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\DocumentPart;
use App\Domain\AI\Data\Events\ReasoningDelta;
use App\Domain\AI\Data\Events\TextDelta;
use App\Domain\AI\Data\ImagePart;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\FinishReason;
use App\Domain\AI\Http\ErrorMapper;
use App\Domain\AI\Providers\HttpChatProvider;
use App\Domain\AI\Providers\StreamState;
use Generator;
use Illuminate\Http\Client\Response;

/**
 * OpenAI Responses API (POST /responses, stream: true, store: false).
 *
 * Usage normalization: input_tokens includes cached tokens and
 * output_tokens includes reasoning tokens; both are subtracted so that
 * TokenUsage fields are disjoint.
 */
final class OpenAIChatProvider extends HttpChatProvider
{
    protected function name(): string
    {
        return 'openai';
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->apiKey];
    }

    protected function payload(ChatRequest $request): array
    {
        return array_filter([
            'model' => $request->model,
            'instructions' => $request->systemPrompt,
            'input' => array_map(self::message(...), $request->messages),
            'max_output_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
            'stream' => true,
            'store' => false,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(ChatMessage $message): array
    {
        if (! $message->hasParts()) {
            return ['role' => $message->role->value, 'content' => $message->text];
        }

        $content = array_map(
            static fn (ImagePart|DocumentPart $part): array => $part instanceof ImagePart
                ? ['type' => 'input_image', 'image_url' => $part->dataUrl()]
                : ['type' => 'input_file', 'filename' => $part->name, 'file_data' => $part->dataUrl()],
            $message->parts,
        );

        if ($message->text !== '') {
            $content[] = ['type' => 'input_text', 'text' => $message->text];
        }

        return ['role' => $message->role->value, 'content' => $content];
    }

    protected function streamUrl(ChatRequest $request): string
    {
        return $this->baseUrl.'/responses';
    }

    protected function countUrl(ChatRequest $request): string
    {
        return $this->baseUrl.'/responses/input_tokens';
    }

    protected function countPayload(ChatRequest $request, array $payload): array
    {
        return array_intersect_key($payload, array_flip(['model', 'instructions', 'input']));
    }

    protected function countFromResponse(array $body): int
    {
        return (int) ($body['input_tokens'] ?? 0);
    }

    protected function requestId(Response $response): ?string
    {
        return $response->header('x-request-id') ?: null;
    }

    protected function translate(Generator $events, StreamState $state): Generator
    {
        $reason = FinishReason::Error;

        foreach ($events as $event) {
            $data = $event->json() ?? [];
            $type = $event->event ?? ($data['type'] ?? null);

            switch ($type) {
                case 'response.output_text.delta':
                    yield new TextDelta((string) ($data['delta'] ?? ''));
                    break;

                case 'response.reasoning_summary_text.delta':
                    yield new ReasoningDelta((string) ($data['delta'] ?? ''));
                    break;

                case 'response.completed':
                case 'response.incomplete':
                    $response = is_array($data['response'] ?? null) ? $data['response'] : [];
                    $state->requestId ??= is_string($response['id'] ?? null) ? $response['id'] : null;
                    $state->addUsage(self::usage($response['usage'] ?? []));
                    $reason = $type === 'response.completed'
                        ? FinishReason::Stop
                        : match ($response['incomplete_details']['reason'] ?? null) {
                            'max_output_tokens' => FinishReason::Length,
                            'content_filter' => FinishReason::ContentFilter,
                            default => FinishReason::Error,
                        };
                    break;

                case 'response.failed':
                    $error = $data['response']['error'] ?? [];
                    throw ErrorMapper::fromStreamError('openai', $error['code'] ?? null, $error['message'] ?? null);
                case 'error':
                    throw ErrorMapper::fromStreamError('openai', $data['code'] ?? null, $data['message'] ?? null);
            }
        }

        return $reason;
    }

    private static function usage(mixed $usage): TokenUsage
    {
        if (! is_array($usage)) {
            return new TokenUsage;
        }

        $input = (int) ($usage['input_tokens'] ?? 0);
        $cached = (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $reasoning = (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? 0);

        return new TokenUsage(
            input: max(0, $input - $cached),
            cachedInput: $cached,
            output: max(0, $output - $reasoning),
            reasoning: $reasoning,
        );
    }
}
