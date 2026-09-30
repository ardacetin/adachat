<?php

namespace App\Domain\Conversations\Services;

use App\Domain\AI\Data\ChatMessage;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Enums\MessageRole;
use App\Domain\AI\Exceptions\ContextLengthExceeded;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Models\Message;
use App\Models\ModelAlias;
use Illuminate\Support\Collection;

/**
 * Builds the provider request for the next answer: the alias system prompt
 * plus the conversation's active branch, trimmed from the oldest turns so
 * that it fits the model's context window next to the output cap. The exact
 * size is measured afterwards by the provider's token counter.
 */
final class ContextBuilder
{
    /** Rough characters per token, only for trimming (the real count comes later). */
    private const CHARS_PER_TOKEN = 3;

    private const PER_MESSAGE_OVERHEAD = 4;

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

        foreach ($history->reverse() as $message) {
            if (! self::usable($message)) {
                continue;
            }

            $cost = self::estimate($message->content) + self::PER_MESSAGE_OVERHEAD;

            if ($used + $cost > $inputBudget) {
                if ($messages === []) {
                    throw new ContextLengthExceeded('The message is too long for this model.');
                }

                break;
            }

            $used += $cost;
            array_unshift($messages, new ChatMessage($message->role, $message->content));
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
        return $message->content !== ''
            && ($message->role === MessageRole::User || $message->status !== MessageStatus::Streaming);
    }

    private static function estimate(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }
}
