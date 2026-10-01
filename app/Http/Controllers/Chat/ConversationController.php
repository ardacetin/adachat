<?php

namespace App\Http\Controllers\Chat;

use App\Domain\AI\Services\AliasAccess;
use App\Domain\Conversations\Services\ConversationThread;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ModelAlias;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    public function __construct(private readonly AliasAccess $aliases) {}

    /**
     * New conversation.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('chat/index', $this->shared($this->user($request)));
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        Gate::authorize('view', $conversation);

        return Inertia::render('chat/show', [
            ...$this->shared($this->user($request)),
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'model_alias_id' => $conversation->model_alias_id,
            ],
            'messages' => ConversationThread::active($conversation)->map(fn (Message $message) => [
                'id' => $message->id,
                'role' => $message->role->value,
                'content' => $message->content,
                'status' => $message->status->value,
                'error_code' => $message->error_code,
                'finish_reason' => $message->finish_reason,
                'output_capped' => (bool) ($message->metadata['output_capped'] ?? false),
                'attachments' => $message->attachments->map(fn (MessageAttachment $attachment) => $attachment->toClient())->values(),
            ])->values(),
        ]);
    }

    public function update(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('update', $conversation);

        $validated = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $conversation->forceFill(['title' => trim($validated['title'])])->save();

        return back();
    }

    public function destroy(Conversation $conversation): RedirectResponse
    {
        Gate::authorize('delete', $conversation);

        $conversation->delete();

        return to_route('home');
    }

    /**
     * Props every chat page needs: the history sidebar and the model selector.
     *
     * @return array<string, mixed>
     */
    private function shared(User $user): array
    {
        $locale = app()->getLocale();

        return [
            'aliases' => $this->aliases->availableFor($user)->map(fn (ModelAlias $alias) => [
                'id' => $alias->id,
                'name' => $alias->localizedName($locale),
                'description' => $alias->description[$locale] ?? $alias->description['en'] ?? null,
                'supports_vision' => $alias->aiModel->supports_vision,
                // The underlying model is shown only when the admin allows it.
                'details' => $alias->show_model_details
                    ? "{$alias->aiModel->display_name} · {$alias->aiModel->provider->name}"
                    : null,
            ])->values(),
            'conversations' => Inertia::defer(fn () => $user->conversations()
                ->orderByDesc('last_message_at')
                ->limit(50)
                ->get(['id', 'title', 'last_message_at'])
                ->map(fn (Conversation $conversation) => [
                    'id' => $conversation->id,
                    'title' => $conversation->title,
                ])),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
