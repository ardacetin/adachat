<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Conversations\Services\ChatPageProps;
use App\Domain\Conversations\Services\ConversationThread;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    public function __construct(private readonly ChatPageProps $page) {}

    /**
     * New conversation.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('chat/index', $this->page->for($this->user($request)));
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        Gate::authorize('view', $conversation);

        return Inertia::render('chat/show', [
            ...$this->page->for($this->user($request)),
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'model_alias_id' => $conversation->model_alias_id,
                'pinned' => $conversation->pinned_at !== null,
            ],
            'assistant' => $conversation->assistant_id === null ? null : AssistantGalleryController::summary(
                $conversation->loadMissing('assistant')->assistant ?? abort(404),
            ),
            'messages' => ConversationThread::active($conversation)->map(fn (Message $message) => [
                'id' => $message->id,
                'role' => $message->role->value,
                'content' => $message->content,
                'status' => $message->status->value,
                'error_code' => $message->error_code,
                'finish_reason' => $message->finish_reason,
                'output_capped' => (bool) ($message->metadata['output_capped'] ?? false),
                'sources' => $message->metadata['sources'] ?? [],
                'attachments' => $message->attachments->map(fn (MessageAttachment $attachment) => $attachment->toClient())->values(),
            ])->values(),
        ]);
    }

    public function update(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('update', $conversation);

        $validated = $request->validate([
            'title' => ['required_without:pinned', 'string', 'max:255'],
            'pinned' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['title'])) {
            $conversation->title = trim($validated['title']);
        }

        if ($request->has('pinned')) {
            $conversation->pinned_at = $request->boolean('pinned') ? ($conversation->pinned_at ?? now()) : null;
        }

        $conversation->save();

        return back();
    }

    public function destroy(Conversation $conversation): RedirectResponse
    {
        Gate::authorize('delete', $conversation);

        $conversation->delete();

        return to_route('home');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
