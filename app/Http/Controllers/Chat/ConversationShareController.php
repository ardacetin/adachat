<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Conversations\Services\ConversationSharing;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationShare;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only links to a conversation (docs/sharing.md). Only signed-in,
 * active users of the institution can open one; the owner can revoke it.
 */
class ConversationShareController extends Controller
{
    public function __construct(private readonly ConversationSharing $sharing) {}

    /**
     * The link is in the response only: its token is not stored.
     */
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('update', $conversation);
        abort_unless($this->sharing->enabled(), 404);

        if (! $this->sharing->canShare($conversation)) {
            return response()->json(['message' => __('chat.share.too_many_links', ['max' => config('ada.sharing.max_links_per_conversation')])], 422);
        }

        if (! $this->sharing->takeDailyAllowance($this->user($request))) {
            return response()->json(['message' => __('chat.share.daily_limit')], 429);
        }

        [$share, $token] = $this->sharing->share($conversation, $this->user($request));

        return response()->json([
            'url' => route('shares.show', $token),
            'link' => self::link($share),
        ], 201);
    }

    public function destroy(Request $request, ConversationShare $share): RedirectResponse
    {
        abort_unless($share->user_id === $this->user($request)->id, 404);

        $this->sharing->revoke($share);

        return back();
    }

    public function show(Request $request, string $token): Response
    {
        $share = $this->sharing->find($token) ?? abort(404);
        $viewer = $this->user($request);
        $this->sharing->recordView($share, $viewer);

        $locale = app()->getLocale();
        $messages = array_map(fn (array $message) => [
            'role' => $message['role'],
            'content' => ($message['withheld'] ?? false) ? __('chat.share.withheld') : $message['content'],
            'withheld' => $message['withheld'] ?? false,
            'model' => $message['alias'] === null ? null : ($message['alias'][$locale] ?? $message['alias']['en'] ?? null),
            'attachments' => $message['attachments'],
            'sources' => $message['sources'],
        ], $share->snapshot['messages']);

        $response = Inertia::render('shares/show', [
            'share' => [
                'token' => $token,
                'title' => $share->title,
                'shared_at' => $share->created_at?->toIso8601String(),
                'by' => $share->user->name,
                'own' => $share->user_id === $viewer->id,
            ],
            'messages' => $messages,
        ])->toResponse($request);

        // Never cached on the way and never indexed.
        $response->headers->add([
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);

        return $response;
    }

    public function copy(Request $request, string $token): RedirectResponse
    {
        $share = $this->sharing->find($token) ?? abort(404);

        if (! $this->sharing->takeDailyAllowance($this->user($request))) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('chat.share.daily_limit')]);

            return back();
        }

        $conversation = $this->sharing->copy($share, $this->user($request));

        return to_route('conversations.show', $conversation);
    }

    /**
     * @return array{id: int, created_at: string|null, view_count: int}
     */
    public static function link(ConversationShare $share): array
    {
        return [
            'id' => $share->id,
            'created_at' => $share->created_at?->toIso8601String(),
            'view_count' => $share->view_count,
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
