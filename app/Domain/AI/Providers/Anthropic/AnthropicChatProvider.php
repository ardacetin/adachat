<?php

namespace App\Domain\AI\Providers\Anthropic;

use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\DocumentPart;
use App\Domain\AI\Data\Events\ReasoningDelta;
use App\Domain\AI\Data\Events\SourceFound;
use App\Domain\AI\Data\Events\TextDelta;
use App\Domain\AI\Data\Events\WebSearchStarted;
use App\Domain\AI\Data\ImagePart;
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
 *
 * Web search uses the basic server tool (web_search_20250305): it works with
 * every Claude model, and search results stay out of later turns because
 * Ada keeps only the answer text. A turn the API pauses (pause_turn) during
 * its searches is continued: the content streamed so far is sent back as
 * the assistant turn, and the server resumes where it stopped.
 */
final class AnthropicChatProvider extends HttpChatProvider
{
    public const API_VERSION = '2023-06-01';

    public const WEB_SEARCH_TOOL = 'web_search_20250305';

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
            // Assistant documents: the system prompt is cached for five
            // minutes; reads cost a tenth of the input price.
            'system' => $request->cacheSystemPrompt && $request->systemPrompt !== null
                ? [['type' => 'text', 'text' => $request->systemPrompt, 'cache_control' => ['type' => 'ephemeral']]]
                : $request->systemPrompt,
            'messages' => array_map(self::message(...), $request->messages),
            'max_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
            'tools' => $request->webSearchMaxUses === null ? null : [[
                'type' => self::WEB_SEARCH_TOOL,
                'name' => 'web_search',
                'max_uses' => $request->webSearchMaxUses,
            ]],
            'stream' => true,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Images and documents come before the text, as Anthropic recommends.
     *
     * @return array<string, mixed>
     */
    private static function message(ChatMessage $message): array
    {
        if (! $message->hasParts()) {
            return ['role' => $message->role->value, 'content' => $message->text];
        }

        $content = array_map(
            static fn (ImagePart|DocumentPart $part): array => [
                'type' => $part instanceof ImagePart ? 'image' : 'document',
                'source' => ['type' => 'base64', 'media_type' => $part->mime, 'data' => $part->base64],
            ],
            $message->parts,
        );

        if ($message->text !== '') {
            $content[] = ['type' => 'text', 'text' => $message->text];
        }

        return ['role' => $message->role->value, 'content' => $content];
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
        return array_intersect_key($payload, array_flip(['model', 'system', 'messages', 'tools']));
    }

    protected function countFromResponse(array $body): int
    {
        return (int) ($body['input_tokens'] ?? 0);
    }

    protected function requestId(Response $response): ?string
    {
        return $response->header('request-id') ?: null;
    }

    protected function continuation(ChatRequest $request, array $payload, StreamState $state): ?array
    {
        // The output cap and the searches hold for the whole turn, across its requests.
        $used = $state->usage ?? new TokenUsage;
        $remaining = $request->maxOutputTokens - $used->totalOutput();

        // Nothing streamed yet: there is no turn to resume.
        if ($remaining < 1 || $state->continuation === null || $state->continuation === []) {
            return null;
        }

        /** @var list<array<string, mixed>> $messages */
        $messages = $payload['messages'];
        $messages[] = ['role' => 'assistant', 'content' => $state->continuation];
        $payload['messages'] = $messages;
        $payload['max_tokens'] = $remaining;

        if ($request->webSearchMaxUses !== null) {
            // The tool must stay declared while the turn holds its calls.
            $payload['tools'] = [[
                'type' => self::WEB_SEARCH_TOOL,
                'name' => 'web_search',
                'max_uses' => max(1, $request->webSearchMaxUses - $used->webSearches),
            ]];
        }

        return $payload;
    }

    protected function translate(Generator $events, StreamState $state): Generator
    {
        $reason = FinishReason::Error;
        // Server tool inputs arrive as streamed JSON, per content block index.
        $json = [];
        // The turn's content blocks as the API sends them, to continue a paused turn.
        $blocks = [];

        foreach ($events as $event) {
            $data = $event->json() ?? [];

            switch ($event->event ?? ($data['type'] ?? null)) {
                case 'message_start':
                    $message = is_array($data['message'] ?? null) ? $data['message'] : [];
                    $state->requestId ??= is_string($message['id'] ?? null) ? $message['id'] : null;
                    $state->addUsage(self::usage($message['usage'] ?? []));
                    break;

                case 'content_block_start':
                    $block = is_array($data['content_block'] ?? null) ? $data['content_block'] : [];
                    $index = (int) ($data['index'] ?? 0);
                    $blocks[$index] = $block;

                    if (($block['type'] ?? null) === 'server_tool_use') {
                        $input = is_array($block['input'] ?? null) ? $block['input'] : [];
                        $json[$index] = $input !== [] ? (string) json_encode($input) : '';
                    } elseif (($block['type'] ?? null) === 'web_search_tool_result' && is_array($block['content'] ?? null) && array_is_list($block['content'])) {
                        // A list of results; an error is a single object.
                        foreach ($block['content'] as $result) {
                            if (is_array($result) && is_string($result['url'] ?? null)) {
                                yield new SourceFound($result['url'], self::title($result), cited: false);
                            }
                        }
                    }
                    break;

                case 'content_block_delta':
                    $delta = $data['delta'] ?? [];
                    $index = (int) ($data['index'] ?? 0);

                    switch ($delta['type'] ?? null) {
                        case 'text_delta':
                            $text = (string) ($delta['text'] ?? '');
                            self::append($blocks, $index, 'text', $text);
                            yield new TextDelta($text);
                            break;
                        case 'thinking_delta':
                            $thinking = (string) ($delta['thinking'] ?? '');
                            self::append($blocks, $index, 'thinking', $thinking);
                            yield new ReasoningDelta($thinking);
                            break;
                        case 'signature_delta':
                            if (isset($blocks[$index])) {
                                $blocks[$index]['signature'] = (string) ($delta['signature'] ?? '');
                            }
                            break;
                        case 'input_json_delta':
                            if (isset($json[$index])) {
                                $json[$index] .= (string) ($delta['partial_json'] ?? '');
                            }
                            break;
                        case 'citations_delta':
                            $citation = $delta['citation'] ?? [];

                            if (is_array($citation) && isset($blocks[$index])) {
                                $citations = is_array($blocks[$index]['citations'] ?? null) ? $blocks[$index]['citations'] : [];
                                $citations[] = $citation;
                                $blocks[$index]['citations'] = $citations;
                            }

                            if (is_array($citation) && is_string($citation['url'] ?? null)) {
                                yield new SourceFound($citation['url'], self::title($citation));
                            }
                            break;
                    }
                    break;

                case 'content_block_stop':
                    $index = (int) ($data['index'] ?? 0);

                    if (isset($json[$index])) {
                        $input = json_decode($json[$index] === '' ? '{}' : $json[$index], true);
                        unset($json[$index]);
                        $blocks[$index]['input'] = is_array($input) ? $input : [];

                        if (($blocks[$index]['name'] ?? null) === 'web_search') {
                            yield new WebSearchStarted(is_string($input['query'] ?? null) ? $input['query'] : null);
                        }
                    }
                    break;

                case 'message_delta':
                    // Cumulative usage for the whole message; with server
                    // tools it includes the input read from search results.
                    $state->addUsage(self::usage($data['usage'] ?? []));
                    if (($data['delta']['stop_reason'] ?? null) === 'pause_turn') {
                        $state->continuation = self::content($blocks);
                    }

                    $reason = match ($data['delta']['stop_reason'] ?? null) {
                        'end_turn', 'stop_sequence', 'tool_use' => FinishReason::Stop,
                        'max_tokens', 'pause_turn' => FinishReason::Length,
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

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private static function append(array &$blocks, int $index, string $field, string $text): void
    {
        if (isset($blocks[$index])) {
            $blocks[$index][$field] = (is_string($blocks[$index][$field] ?? null) ? $blocks[$index][$field] : '').$text;
        }
    }

    /**
     * The streamed blocks in order, as the assistant turn to send back. The
     * API refuses empty text blocks.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private static function content(array $blocks): array
    {
        ksort($blocks);

        return array_values(array_filter($blocks, static fn (array $block): bool => ($block['type'] ?? null) !== 'text' || ($block['text'] ?? '') !== ''));
    }

    private static function usage(mixed $usage): TokenUsage
    {
        if (! is_array($usage)) {
            return new TokenUsage;
        }

        return new TokenUsage(
            input: (int) ($usage['input_tokens'] ?? 0),
            cachedInput: (int) ($usage['cache_read_input_tokens'] ?? 0),
            cacheWrite: (int) ($usage['cache_creation_input_tokens'] ?? 0),
            output: (int) ($usage['output_tokens'] ?? 0),
            webSearches: (int) ($usage['server_tool_use']['web_search_requests'] ?? 0),
        );
    }

    /**
     * @param  array<mixed>  $item
     */
    private static function title(array $item): ?string
    {
        return is_string($item['title'] ?? null) && $item['title'] !== '' ? $item['title'] : null;
    }
}
