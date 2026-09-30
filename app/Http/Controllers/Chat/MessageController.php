<?php

namespace App\Http\Controllers\Chat;

use App\Domain\AI\Enums\MessageRole;
use App\Domain\AI\Services\AliasAccess;
use App\Domain\Conversations\Data\ChatStreamEvent;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Services\ChatGenerationService;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelAlias;
use App\Models\User;
use Generator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
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
    ) {}

    public function store(Request $request): StreamedResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:'.(int) config('ada.chat.max_message_chars', 32000)],
            'model_alias_id' => ['required', 'integer'],
            'conversation_id' => ['nullable', 'uuid'],
        ]);

        $conversation = null;

        if (isset($validated['conversation_id'])) {
            $conversation = Conversation::query()->whereKey($validated['conversation_id'])->firstOrFail();
            Gate::authorize('update', $conversation);
        }

        $alias = $this->alias($user, $validated['model_alias_id']);
        $content = trim($validated['content']);

        return $this->sse(fn (callable $clientGone) => $this->chat->send($user, $conversation, $alias, $content, $clientGone(...)));
    }

    public function regenerate(Request $request, Message $message): StreamedResponse
    {
        $user = $this->user($request);
        Gate::authorize('update', $message->conversation);

        $validated = $request->validate(['model_alias_id' => ['nullable', 'integer']]);
        $alias = $this->alias($user, $validated['model_alias_id'] ?? $message->model_alias_id);

        return $this->sse(fn (callable $clientGone) => $this->chat->regenerate($user, $message, $alias, $clientGone(...)));
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
