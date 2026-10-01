<?php

namespace App\Domain\Conversations\Services;

use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\ImagePart;
use App\Domain\AI\Enums\MessageRole;
use App\Domain\AI\Exceptions\ContextLengthExceeded;
use App\Domain\Attachments\AttachmentStore;
use App\Domain\Attachments\Enums\AttachmentKind;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ModelAlias;
use Illuminate\Support\Collection;

/**
 * Builds the provider request for the next answer: the alias system prompt
 * plus the conversation's active branch, trimmed from the oldest turns so
 * that it fits the model's context window next to the output cap. The exact
 * size is measured afterwards by the provider's token counter.
 *
 * Attachments: text files are appended to their message's text as fenced
 * blocks; images become image parts. Providers are stateless, so the images
 * of earlier turns are sent again while those turns are in the context, up
 * to ada.attachments.max_request_mb per request (newest first). Older
 * images beyond that are replaced by a short note.
 */
final class ContextBuilder
{
    /** Rough characters per token, only for trimming (the real count comes later). */
    private const CHARS_PER_TOKEN = 3;

    private const PER_MESSAGE_OVERHEAD = 4;

    public function __construct(private readonly AttachmentStore $attachments) {}

    /**
     * @param  Collection<int, Message>  $history  oldest first, ending with the new user message
     *
     * @throws ContextLengthExceeded when even the newest message alone does not fit
     */
    public function build(ModelAlias $alias, Collection $history): ChatRequest
    {
        $model = $alias->aiModel;
        $maxOutput = $alias->effectiveMaxOutputTokens();
        // Leave room for at least a quarter of the window for the answer.
        $inputBudget = $model->context_window - min($maxOutput, intdiv($model->context_window, 4));
        $inputBudget -= self::estimate((string) $alias->system_prompt);

        $messages = [];
        $used = 0;
        $imageBytes = (int) (config('ada.attachments.max_request_mb') * 1024 * 1024);

        foreach ($history->reverse() as $message) {
            if (! self::usable($message)) {
                continue;
            }

            $attachments = self::attachmentsOf($message);
            $text = self::withTextFiles($message->content, $attachments);
            $images = $attachments->filter(fn (MessageAttachment $file) => $file->kind === AttachmentKind::Image);

            $cost = self::estimate($text) + self::PER_MESSAGE_OVERHEAD
                + (int) $images->sum(fn (MessageAttachment $image) => $image->token_estimate);

            if ($used + $cost > $inputBudget) {
                if ($messages === []) {
                    throw new ContextLengthExceeded('The message is too long for this model.');
                }

                break;
            }

            $used += $cost;
            $parts = [];
            $omitted = [];

            foreach ($images as $image) {
                // The newest message always carries its images.
                if ($messages !== [] && $image->size > $imageBytes) {
                    $omitted[] = $image->original_name;

                    continue;
                }

                $imageBytes -= $image->size;
                $parts[] = new ImagePart($image->mime, base64_encode($this->attachments->contents($image)), $image->token_estimate);
            }

            foreach ($omitted as $name) {
                $text .= "\n\n[Image not sent again with this request: {$name}]";
            }

            array_unshift($messages, new ChatMessage($message->role, $text, $parts));
        }

        // Providers expect the conversation to start with a user turn.
        while ($messages !== [] && $messages[0]->role !== MessageRole::User) {
            array_shift($messages);
        }

        return new ChatRequest(
            model: $model->provider_model_id,
            messages: $messages,
            maxOutputTokens: $maxOutput,
            systemPrompt: filled($alias->system_prompt) ? $alias->system_prompt : null,
            temperature: $alias->temperature !== null ? (float) $alias->temperature : null,
        );
    }

    /**
     * Messages that carry context: user turns and answers with content.
     * Failed answers without text are skipped.
     */
    private static function usable(Message $message): bool
    {
        return ($message->content !== '' || self::attachmentsOf($message)->isNotEmpty())
            && ($message->role === MessageRole::User || $message->status !== MessageStatus::Streaming);
    }

    /**
     * @return Collection<int, MessageAttachment>
     */
    private static function attachmentsOf(Message $message): Collection
    {
        return $message->role === MessageRole::User && $message->relationLoaded('attachments')
            ? $message->attachments->values()
            : collect();
    }

    /**
     * The message text followed by its text files, each in a code fence
     * longer than any backtick run inside it.
     *
     * @param  Collection<int, MessageAttachment>  $attachments
     */
    private static function withTextFiles(string $text, Collection $attachments): string
    {
        foreach ($attachments as $file) {
            if ($file->kind !== AttachmentKind::Text) {
                continue;
            }

            $body = rtrim((string) $file->extracted_text, "\n");
            preg_match_all('/`+/', $body, $runs);
            $fence = str_repeat('`', max(3, ...array_map(fn (string $run) => strlen($run) + 1, $runs[0] ?: [''])));

            $text = ltrim($text."\n\n{$fence}{$file->original_name}\n{$body}\n{$fence}", "\n");
        }

        return $text;
    }

    private static function estimate(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }
}
