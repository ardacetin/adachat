<?php

namespace App\Domain\Conversations\Services;

use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\DocumentPart;
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
 * (the institution's rules come first), an assistant's instructions after
 * it, plus the conversation's active branch, trimmed from the oldest turns so
 * that it fits the model's context window next to the output cap. The exact
 * size is measured afterwards by the provider's token counter.
 *
 * Attachments: images become image parts, and PDFs become document parts
 * when the model reads files natively. Text and code files, Office files and
 * the other PDFs are appended to their message's text as fenced blocks.
 * Providers are stateless, so the files of earlier turns are sent again
 * while those turns are in the context, up to ada.attachments.max_request_mb
 * of files per request (newest first). Older ones beyond that fall back to
 * their text, or are replaced by a short note.
 */
final class ContextBuilder
{
    /** Rough characters per token, only for trimming (the real count comes later). */
    private const CHARS_PER_TOKEN = 3;

    private const PER_MESSAGE_OVERHEAD = 4;

    public function __construct(private readonly AttachmentStore $attachments) {}

    /**
     * @param  Collection<int, Message>  $history  oldest first, ending with the new user message
     * @param  string|null  $instructions  an assistant's instructions (and documents)
     * @param  bool  $cacheInstructions  the instructions are long and the same every time
     * @param  bool  $documentsAsText  send PDFs as their text, e.g. so personal data in them can be masked
     *
     * @throws ContextLengthExceeded when even the newest message alone does not fit
     */
    public function build(ModelAlias $alias, Collection $history, ?string $instructions = null, bool $cacheInstructions = false, bool $documentsAsText = false): ChatRequest
    {
        $systemPrompt = self::systemPrompt($alias, $instructions);
        $model = $alias->aiModel;
        $maxOutput = $alias->effectiveMaxOutputTokens();
        // Leave room for at least a quarter of the window for the answer.
        $inputBudget = $model->context_window - min($maxOutput, intdiv($model->context_window, 4));
        $inputBudget -= self::estimate((string) $systemPrompt);

        $messages = [];
        $used = 0;
        $bytesLeft = (int) (config('ada.attachments.max_request_mb') * 1024 * 1024);
        $nativePdf = ! $documentsAsText && $model->supports_files && $model->provider->driver->sendsDocuments();

        foreach ($history->reverse() as $message) {
            if (! self::usable($message)) {
                continue;
            }

            // Images and native PDFs share the request byte budget, newest
            // first; the newest message always carries its own files.
            $binary = [];
            $asText = [];
            $notes = [];

            foreach (self::attachmentsOf($message) as $file) {
                $sendable = $file->kind === AttachmentKind::Image || ($file->kind === AttachmentKind::Pdf && $nativePdf);
                $fits = $messages === [] || $file->size <= $bytesLeft;

                if ($sendable && $fits) {
                    $bytesLeft -= $file->size;
                    $binary[] = $file;
                } elseif ($file->kind === AttachmentKind::Image || ($file->kind === AttachmentKind::Pdf && trim((string) $file->extracted_text) === '')) {
                    $notes[] = "[File not sent again with this request: {$file->original_name}]";
                } else {
                    $asText[] = $file;
                }
            }

            $text = self::withFiles($message->content, $asText);

            foreach ($notes as $note) {
                $text = ltrim($text."\n\n".$note, "\n");
            }

            $cost = self::estimate($text) + self::PER_MESSAGE_OVERHEAD
                + array_sum(array_map(fn (MessageAttachment $file) => $file->token_estimate, $binary));

            if ($used + $cost > $inputBudget) {
                if ($messages === []) {
                    throw new ContextLengthExceeded('The message is too long for this model.');
                }

                break;
            }

            $used += $cost;
            $parts = array_map(fn (MessageAttachment $file) => $this->part($file), $binary);

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
            systemPrompt: $systemPrompt,
            temperature: $alias->temperature !== null ? (float) $alias->temperature : null,
            cacheSystemPrompt: $cacheInstructions && $systemPrompt !== null,
        );
    }

    /**
     * The alias system prompt (institutional rules) first, then the
     * assistant's instructions.
     */
    public static function systemPrompt(ModelAlias $alias, ?string $instructions): ?string
    {
        $parts = array_filter([trim((string) $alias->system_prompt), trim((string) $instructions)], fn (string $part) => $part !== '');

        return $parts === [] ? null : implode("\n\n", $parts);
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

    private function part(MessageAttachment $file): ImagePart|DocumentPart
    {
        $base64 = base64_encode($this->attachments->contents($file));

        return $file->kind === AttachmentKind::Image
            ? new ImagePart($file->mime, $base64, $file->token_estimate)
            : new DocumentPart($file->mime, $base64, $file->original_name, $file->token_estimate);
    }

    /**
     * The message text followed by the text of its files (text and code,
     * and PDF or Office documents not sent as files), each in a code fence
     * longer than any backtick run inside it.
     *
     * @param  list<MessageAttachment>  $files
     */
    private static function withFiles(string $text, array $files): string
    {
        foreach ($files as $file) {
            $body = rtrim((string) $file->extracted_text, "\n");
            preg_match_all('/`+/', $body, $runs);
            $fence = str_repeat('`', max(3, ...array_map(fn (string $run) => strlen($run) + 1, $runs[0] ?: [''])));
            $label = $file->kind === AttachmentKind::Pdf && $file->page_count !== null
                ? "{$file->original_name} ({$file->page_count} pages)"
                : $file->original_name;

            $text = ltrim($text."\n\n{$fence}{$label}\n{$body}\n{$fence}", "\n");
        }

        return $text;
    }

    private static function estimate(string $text): int
    {
        return self::estimateTokens($text);
    }

    /**
     * Rough token count used for trimming and limits; the provider's
     * counter measures the real request.
     */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }
}
