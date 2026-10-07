<?php

namespace App\Http\Controllers\Chat;

use App\Domain\AI\Enums\MessageRole;
use App\Domain\AI\Services\AliasAccess;
use App\Domain\Assistants\AssistantAccess;
use App\Domain\Attachments\Enums\AttachmentKind;
use App\Domain\Conversations\Data\ChatStreamEvent;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Services\ChatGenerationService;
use App\Domain\PersonalData\PersonalDataCheck;
use App\Domain\PersonalData\PersonalDataLabels;
use App\Http\Controllers\Controller;
use App\Models\Assistant;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ModelAlias;
use App\Models\User;
use Generator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chat generation over server-sent events on a POST request
 * (docs/architecture.md §6). Authorization and validation failures are
 * normal HTTP errors; everything after that is reported as SSE events.
 */
class MessageController extends Controller
{
    public function __construct(
        private readonly ChatGenerationService $chat,
        private readonly AliasAccess $aliases,
        private readonly AssistantAccess $assistants,
        private readonly PersonalDataCheck $personalData,
    ) {}

    public function store(Request $request): StreamedResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'content' => ['required_without:attachment_ids', 'nullable', 'string', 'max:'.(int) config('ada.chat.max_message_chars', 32000)],
            'model_alias_id' => ['required', 'integer'],
            'conversation_id' => ['nullable', 'uuid'],
            // Starts a new conversation with an assistant.
            'assistant_id' => ['nullable', 'integer'],
            'attachment_ids' => ['nullable', 'array', 'max:'.(int) config('ada.attachments.max_per_message')],
            'attachment_ids.*' => ['uuid', 'distinct'],
            'web_search' => ['sometimes', 'boolean'],
            // The user saw the personal data warning and sends anyway.
            'personal_data_confirmed' => ['sometimes', 'boolean'],
        ], [
            'attachment_ids.max' => __('chat.attachments.too_many', ['max' => (int) config('ada.attachments.max_per_message')]),
        ]);

        $conversation = null;

        if (isset($validated['conversation_id'])) {
            $conversation = Conversation::query()->whereKey($validated['conversation_id'])->firstOrFail();
            Gate::authorize('update', $conversation);
        }

        $assistant = $this->assistant($user, $conversation, $validated['assistant_id'] ?? null);
        $alias = $this->alias($user, $assistant->model_alias_id ?? $validated['model_alias_id']);

        // An assistant's conversation keeps the assistant's model.
        if ($assistant !== null && (int) $validated['model_alias_id'] !== $assistant->model_alias_id) {
            throw ValidationException::withMessages(['model_alias_id' => __('chat.assistant_model_locked')]);
        }

        $content = trim((string) ($validated['content'] ?? ''));
        $attachments = $this->attachments($user, $alias, $validated['attachment_ids'] ?? []);

        if ($content === '' && $attachments->isEmpty()) {
            throw ValidationException::withMessages(['content' => __('validation.required', ['attribute' => 'content'])]);
        }

        $this->checkPersonalData($content, $attachments, $request->boolean('personal_data_confirmed'));

        $webSearch = $this->webSearch($alias, $request->boolean('web_search'), $assistant);

        return $this->sse(fn (callable $clientGone) => $this->chat->send($user, $conversation, $alias, $content, $clientGone(...), $attachments, $assistant, $webSearch));
    }

    public function regenerate(Request $request, Message $message): StreamedResponse
    {
        $user = $this->user($request);
        Gate::authorize('update', $message->conversation);

        $validated = $request->validate(['model_alias_id' => ['nullable', 'integer'], 'web_search' => ['sometimes', 'boolean']]);
        $assistant = $this->assistant($user, $message->conversation, null);
        $alias = $this->alias($user, $assistant->model_alias_id ?? $validated['model_alias_id'] ?? $message->model_alias_id);
        $webSearch = $this->webSearch($alias, $request->boolean('web_search'), $assistant);

        return $this->sse(fn (callable $clientGone) => $this->chat->regenerate($user, $message, $alias, $clientGone(...), $webSearch));
    }

    /**
     * The Stop button: the running request sees the flag and stops the
     * provider stream; the budget is settled for what was generated.
     */
    public function cancel(Message $message): Response
    {
        Gate::authorize('update', $message->conversation);

        if ($message->role === MessageRole::Assistant && $message->status === MessageStatus::Streaming) {
            Cache::put(ChatGenerationService::cancelKey($message->id), true, now()->addMinutes(10));
        }

        return response()->noContent();
    }

    /**
     * A message with personal data the institution blocks, or warns about
     * without the user's confirmation, is refused before anything is
     * stored or sent. The response names the kinds, never the values.
     *
     * @param  EloquentCollection<int, MessageAttachment>  $attachments
     */
    private function checkPersonalData(string $content, EloquentCollection $attachments, bool $confirmed): void
    {
        $text = $attachments->reduce(fn (string $text, MessageAttachment $file): string => $text."\n".$file->extracted_text, $content);
        $refusal = $this->personalData->refusal($text, $confirmed);

        if ($refusal === null) {
            return;
        }

        $kinds = implode(', ', array_map(PersonalDataLabels::label(...), $refusal['kinds']));
        $message = __('chat.personal_data.'.$refusal['action'], ['kinds' => $kinds]);

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => ['content' => [$message]],
            'personal_data' => $refusal,
        ], 422));
    }

    /**
     * @param  callable(callable(): bool): Generator<int, ChatStreamEvent>  $events
     */
    private function sse(callable $events): StreamedResponse
    {
        return response()->stream(function () use ($events): void {
            // Keep control after the browser disconnects so that the provider
            // stream is stopped and the budget settled.
            ignore_user_abort(true);
            set_time_limit((int) config('ada.providers.timeout', 300) + 60);

            foreach ($events(fn (): bool => connection_aborted() === 1) as $event) {
                echo $event->encode();

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * The user's own unsent uploads, in the order given, that the model can read.
     *
     * @param  list<string>  $ids
     * @return EloquentCollection<int, MessageAttachment>
     */
    private function attachments(User $user, ModelAlias $alias, array $ids): EloquentCollection
    {
        if ($ids === []) {
            return new EloquentCollection;
        }

        $found = MessageAttachment::query()->whereKey($ids)->where('user_id', $user->id)->pending()->get();

        if ($found->count() !== count($ids)) {
            throw ValidationException::withMessages(['attachment_ids' => __('chat.attachments.invalid')]);
        }

        $attachments = $found->sortBy(fn (MessageAttachment $file) => array_search($file->id, $ids, true))->values();

        $model = $alias->aiModel;

        if (! $model->supports_vision && $attachments->contains(fn (MessageAttachment $file) => $file->kind === AttachmentKind::Image)) {
            throw ValidationException::withMessages(['attachment_ids' => __('chat.attachments.vision_unsupported')]);
        }

        // A PDF without text (scanned) can only be read by a model that takes the file itself.
        $readsPdfs = $model->supports_files && $model->provider->driver->sendsDocuments();
        $scanned = $attachments->contains(fn (MessageAttachment $file) => $file->kind === AttachmentKind::Pdf && trim((string) $file->extracted_text) === '');

        if ($scanned && ! $readsPdfs) {
            throw ValidationException::withMessages(['attachment_ids' => __('chat.attachments.scanned_pdf_unsupported')]);
        }

        return $attachments;
    }

    /**
     * The assistant of the conversation (or the one a new conversation starts
     * with), when the user may still use it.
     */
    private function assistant(User $user, ?Conversation $conversation, mixed $assistantId): ?Assistant
    {
        $assistantId = $conversation !== null ? $conversation->assistant_id : $assistantId;

        if ($assistantId === null) {
            return null;
        }

        $assistant = is_int($assistantId) || is_string($assistantId) ? $this->assistants->find($user, $assistantId) : null;
        abort_if($assistant === null, 403, __('chat.assistant_not_allowed'));

        return $assistant;
    }

    /**
     * Searches allowed for this message: null when the user did not ask for
     * web search. Asking for it where the alias (or the assistant) does not
     * allow it is an error.
     */
    private function webSearch(ModelAlias $alias, bool $requested, ?Assistant $assistant): ?int
    {
        if (! $requested) {
            return null;
        }

        $maxUses = $assistant === null || $assistant->web_search_enabled ? $alias->webSearchMaxUses() : null;

        return $maxUses ?? throw ValidationException::withMessages(['web_search' => __('chat.web_search_unavailable')]);
    }

    private function alias(User $user, mixed $aliasId): ModelAlias
    {
        $alias = is_int($aliasId) || is_string($aliasId) ? $this->aliases->find($user, $aliasId) : null;
        abort_if($alias === null, 403, __('chat.alias_not_allowed'));

        return $alias;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
