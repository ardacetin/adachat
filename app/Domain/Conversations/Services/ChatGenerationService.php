<?php

namespace App\Domain\Conversations\Services;

use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\Events\Finished;
use App\Domain\AI\Data\Events\TextDelta;
use App\Domain\AI\Data\Events\UsageReported;
use App\Domain\AI\Data\InputTokenCount;
use App\Domain\AI\Data\TokenUsage;
use App\Domain\AI\Enums\FinishReason;
use App\Domain\AI\Enums\MessageRole;
use App\Domain\AI\Exceptions\ProviderException;
use App\Domain\AI\Services\CallbackCancellation;
use App\Domain\AI\Services\ProviderManager;
use App\Domain\AI\Services\TokenCounting;
use App\Domain\Budget\Data\Settlement;
use App\Domain\Budget\Exceptions\BudgetException;
use App\Domain\Budget\Services\BudgetEngine;
use App\Domain\Conversations\Data\ChatStreamEvent;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Exceptions\ChatRefused;
use App\Domain\Usage\Enums\UsageEventStatus;
use App\Models\AiModel;
use App\Models\Assistant;
use App\Models\BudgetReservation;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ModelAlias;
use App\Models\UsageEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * One chat request, start to finish (docs/architecture.md §5.2):
 *
 *   count input → reserve budget → persist messages → stream → settle/release
 *
 * Yields the server-sent events for the browser. No lock is held while
 * streaming; the reservation is always settled or released in `finally`,
 * and the stale-reservation job covers a process that dies.
 */
final class ChatGenerationService
{
    /** Assistant content is written to the database at most this often. */
    private const FLUSH_SECONDS = 1.0;

    /** The cancel flag in the cache is checked at most this often. */
    private const CANCEL_CHECK_SECONDS = 0.5;

    public function __construct(
        private readonly TokenCounting $counting,
        private readonly BudgetEngine $budget,
        private readonly ProviderManager $providers,
        private readonly ContextBuilder $context,
    ) {}

    public static function cancelKey(string $assistantMessageId): string
    {
        return "chat:cancel:{$assistantMessageId}";
    }

    /**
     * Send a new user message, in a new conversation when $conversation is null.
     * $attachments are the user's pending uploads, already checked by the caller.
     *
     * @param  EloquentCollection<int, MessageAttachment>|null  $attachments
     * @param  Closure(): bool  $clientGone
     * @return Generator<int, ChatStreamEvent>
     */
    public function send(User $user, ?Conversation $conversation, ModelAlias $alias, string $content, Closure $clientGone, ?EloquentCollection $attachments = null, ?Assistant $assistant = null): Generator
    {
        $history = $conversation !== null ? ConversationThread::active($conversation) : collect();

        if ($history->last()?->status === MessageStatus::Streaming) {
            yield self::error('generation_in_progress', retryable: true);

            return;
        }

        $parent = $history->last();
        $userMessage = new Message;
        $userMessage->forceFill([
            'parent_message_id' => $parent?->id,
            'role' => MessageRole::User,
            'content' => $content,
            'status' => MessageStatus::Completed,
        ]);
        $userMessage->setRelation('attachments', $attachments ?? new EloquentCollection);

        yield from $this->run($user, $conversation, $alias, $history->push($userMessage), $userMessage, $clientGone, $assistant);
    }

    /**
     * Replace the last answer with a new one from the same user message.
     *
     * @param  Closure(): bool  $clientGone
     * @return Generator<int, ChatStreamEvent>
     */
    public function regenerate(User $user, Message $answer, ModelAlias $alias, Closure $clientGone): Generator
    {
        $conversation = $answer->conversation;
        $history = ConversationThread::active($conversation);

        if ($history->last()?->id !== $answer->id || $answer->role !== MessageRole::Assistant || $answer->status === MessageStatus::Streaming) {
            yield self::error('cannot_regenerate');

            return;
        }

        // The thread up to (and including) the user message being answered.
        $history = $history->slice(0, -1)->values();

        yield from $this->run($user, $conversation, $alias, $history, null, $clientGone, $conversation->loadMissing('assistant')->assistant);
    }

    /**
     * @param  Collection<int, Message>  $history  ends with the user message to answer
     * @param  Message|null  $userMessage  unsaved new user message, null when regenerating
     * @param  Closure(): bool  $clientGone
     * @return Generator<int, ChatStreamEvent>
     */
    private function run(User $user, ?Conversation $conversation, ModelAlias $alias, Collection $history, ?Message $userMessage, Closure $clientGone, ?Assistant $assistant = null): Generator
    {
        $model = $alias->aiModel;

        try {
            $this->throttle($user);
            $request = $this->context->build(
                $alias,
                $history,
                $assistant?->systemInstructions(),
                cacheInstructions: $assistant !== null && $assistant->documents()->exists(),
            );
            $count = $this->counting->count($model, $request);
            $reservation = $this->budget->reserve($user, $model, $count, $alias->effectiveMaxOutputTokens());
        } catch (ChatRefused $refused) {
            yield self::error($refused->errorCode, $refused->retryable);

            return;
        } catch (BudgetException $refused) {
            yield self::error($refused->code(), retryable: false);

            return;
        } catch (ProviderException $failed) {
            self::logProviderFailure($failed, $model);
            yield self::error($failed->code(), $failed->retryable());

            return;
        }

        // Nothing is stored before the budget is secured.
        try {
            [$conversation, $answer] = DB::transaction(fn () => $this->persist($user, $conversation, $alias, $history, $userMessage, $reservation, $assistant));
        } catch (ChatRefused $refused) {
            $this->budget->release($reservation, $refused->errorCode);
            yield self::error($refused->errorCode, $refused->retryable);

            return;
        }

        $outputCapped = $reservation->max_output_tokens < min($alias->effectiveMaxOutputTokens(), $model->context_window - $count->tokens);

        if ($outputCapped) {
            $answer->forceFill(['metadata' => ['output_capped' => true]])->save();
        }

        yield new ChatStreamEvent('message.started', [
            'conversation_id' => $conversation->id,
            'user_message_id' => $userMessage?->id,
            'assistant_message_id' => $answer->id,
            'model_alias_id' => $alias->id,
            'output_capped' => $outputCapped,
        ]);

        yield from $this->stream($user, $conversation, $alias, $request->withMaxOutputTokens($reservation->max_output_tokens), $count, $reservation, $answer, $clientGone);
    }

    /**
     * @param  Closure(): bool  $clientGone
     * @return Generator<int, ChatStreamEvent>
     */
    private function stream(User $user, Conversation $conversation, ModelAlias $alias, ChatRequest $request, InputTokenCount $count, BudgetReservation $reservation, Message $answer, Closure $clientGone): Generator
    {
        $text = '';
        $usage = null;
        $reason = null;
        $requestId = null;
        $started = false;
        $failure = null;
        $lastFlush = microtime(true);

        $deadline = microtime(true) + (int) config('ada.providers.timeout', 300);
        $lastCancelCheck = 0.0;
        $cancelRequested = false;
        $cancellation = new CallbackCancellation(function () use ($clientGone, $deadline, $answer, &$lastCancelCheck, &$cancelRequested): bool {
            if ($cancelRequested || $clientGone() || microtime(true) > $deadline) {
                return true;
            }

            if (microtime(true) - $lastCancelCheck >= self::CANCEL_CHECK_SECONDS) {
                $lastCancelCheck = microtime(true);
                $cancelRequested = Cache::has(self::cancelKey($answer->id));
            }

            return $cancelRequested;
        });

        try {
            foreach ($this->providers->forModel($alias->aiModel)->stream($request, $cancellation) as $event) {
                $started = true;

                if ($event instanceof TextDelta && $event->text !== '') {
                    $text .= $event->text;

                    yield new ChatStreamEvent('delta', ['text' => $event->text]);

                    if (microtime(true) - $lastFlush >= self::FLUSH_SECONDS) {
                        $answer->forceFill(['content' => $text])->save();
                        $lastFlush = microtime(true);
                    }
                } elseif ($event instanceof UsageReported) {
                    $usage = $event->usage;
                } elseif ($event instanceof Finished) {
                    $reason = $event->reason;
                    $requestId = $event->providerRequestId;
                }
            }
        } catch (ProviderException $exception) {
            self::logProviderFailure($exception, $alias->aiModel);
            $failure = $exception;
        } catch (Throwable $exception) {
            report($exception);
            $failure = $exception;
        } finally {
            // Runs even if the client disconnected and the generator is destroyed.
            $event = $this->finish($user, $conversation, $reservation, $answer, $count, $text, $usage, $reason, $requestId, $started, $failure);
        }

        yield $event;
    }

    private function finish(User $user, Conversation $conversation, BudgetReservation $reservation, Message $answer, InputTokenCount $count, string $text, ?TokenUsage $usage, ?FinishReason $reason, ?string $requestId, bool $started, ?Throwable $failure): ChatStreamEvent
    {
        $errorCode = match (true) {
            $failure instanceof ProviderException => $failure->code(),
            $failure !== null => 'internal_error',
            default => null,
        };

        $cancelled = $failure === null && $reason === FinishReason::Cancelled;
        Cache::forget(self::cancelKey($answer->id));

        $answer->forceFill([
            'content' => $text,
            'status' => match (true) {
                $errorCode !== null => MessageStatus::Failed,
                $cancelled => MessageStatus::Cancelled,
                default => MessageStatus::Completed,
            },
            'error_code' => $errorCode,
            'finish_reason' => $reason?->value,
        ])->save();

        $conversation->forceFill(['last_message_at' => CarbonImmutable::now()])->save();

        // The provider refused before generating anything: nothing to pay.
        if (! $started && $failure !== null) {
            $this->budget->release($reservation, 'provider_error');

            return self::error((string) $errorCode, $failure instanceof ProviderException && $failure->retryable(), $answer->id);
        }

        $estimated = $usage === null;
        $usage ??= new TokenUsage(
            input: $count->tokens,
            // Pessimistic 2 bytes per token, never above the cap the provider enforces.
            output: min((int) ceil(strlen($text) / 2), $reservation->max_output_tokens),
        );

        $event = $this->budget->settle($reservation, new Settlement(
            usage: $usage,
            status: match (true) {
                $errorCode !== null => UsageEventStatus::Failed,
                $cancelled => UsageEventStatus::Partial,
                default => UsageEventStatus::Completed,
            },
            isEstimated: $estimated,
            reason: match (true) {
                $errorCode !== null => 'provider_error',
                $cancelled => 'cancelled',
                default => null,
            },
            modelAliasId: $answer->model_alias_id,
            conversationId: $conversation->id,
            messageId: $answer->id,
            providerRequestId: $requestId,
        ));

        if ($errorCode !== null) {
            return self::error($errorCode, $failure instanceof ProviderException && $failure->retryable(), $answer->id);
        }

        return new ChatStreamEvent('message.completed', [
            'assistant_message_id' => $answer->id,
            'status' => $answer->status->value,
            'finish_reason' => $reason?->value,
            'usage' => self::usage($event, $usage),
        ]);
    }

    /**
     * @throws ChatRefused
     */
    private function throttle(User $user): void
    {
        $key = "chat:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, $user->group->requests_per_minute)) {
            throw new ChatRefused('rate_limited', retryable: true);
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * Stores the conversation, the new user message (with its attachments)
     * and the empty answer. Runs in a transaction after the reservation.
     *
     * @param  Collection<int, Message>  $history
     * @return array{0: Conversation, 1: Message}
     *
     * @throws ChatRefused when an attachment was sent meanwhile (double submit)
     */
    private function persist(User $user, ?Conversation $conversation, ModelAlias $alias, Collection $history, ?Message $userMessage, BudgetReservation $reservation, ?Assistant $assistant): array
    {
        if ($conversation === null) {
            $conversation = new Conversation;
            $conversation->forceFill([
                'user_id' => $user->id,
                'title' => self::title($userMessage),
                'assistant_id' => $assistant?->id,
            ]);
        }

        $conversation->forceFill(['model_alias_id' => $alias->id, 'last_message_at' => CarbonImmutable::now()])->save();

        if ($userMessage !== null) {
            $userMessage->conversation_id = $conversation->id;
            $userMessage->save();
            $this->attach($userMessage);
        }

        $answer = new Message;
        $answer->forceFill([
            'conversation_id' => $conversation->id,
            'parent_message_id' => $userMessage->id ?? $history->last()?->id,
            'role' => MessageRole::Assistant,
            'content' => '',
            'status' => MessageStatus::Streaming,
            'model_alias_id' => $alias->id,
            'ai_model_id' => $alias->ai_model_id,
            'reservation_id' => $reservation->id,
        ])->save();

        return [$conversation, $answer];
    }

    /**
     * @throws ChatRefused
     */
    private function attach(Message $message): void
    {
        $ids = $message->attachments->modelKeys();

        if ($ids === []) {
            return;
        }

        $attached = MessageAttachment::query()->whereKey($ids)->pending()->update(['message_id' => $message->id]);

        if ($attached !== count($ids)) {
            throw new ChatRefused('attachments_invalid');
        }
    }

    private static function title(?Message $message): string
    {
        $text = Str::squish((string) $message?->content);

        if ($text === '' && $message !== null && $message->attachments->isNotEmpty()) {
            $text = $message->attachments->first()->original_name;
        }

        return Str::limit($text, 80);
    }

    /**
     * @return array<string, int|string|bool>
     */
    private static function usage(UsageEvent $event, TokenUsage $usage): array
    {
        return [
            'input_tokens' => $usage->totalInput(),
            'output_tokens' => $usage->totalOutput(),
            'cost_usd' => $event->total_cost_usd->toString(),
            'estimated' => $event->is_estimated,
        ];
    }

    private static function error(string $code, bool $retryable = false, ?string $assistantMessageId = null): ChatStreamEvent
    {
        return new ChatStreamEvent('error', array_filter([
            'code' => $code,
            'retryable' => $retryable,
            'assistant_message_id' => $assistantMessageId,
        ], fn ($value) => $value !== null));
    }

    /**
     * The provider's reason ("HTTP 400 — model_not_found — …") for the
     * administrator; users only see the translated error code. The message
     * never contains keys or prompts (ErrorMapper).
     */
    private static function logProviderFailure(ProviderException $exception, AiModel $model): void
    {
        Log::warning('AI provider request failed.', [
            'code' => $exception->code(),
            'status' => $exception->status,
            'model' => $model->provider_model_id,
            'ai_model_id' => $model->id,
            'provider' => $exception->getMessage(),
        ]);
    }
}
