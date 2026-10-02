<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Conversations\Services\ChatPageProps;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Searches the signed-in user's own conversations: titles, messages and
 * attachment names. Nobody else's conversations are ever searched, also not
 * by administrators.
 */
class SearchController extends Controller
{
    private const MAX_RESULTS = 50;

    /** Characters shown around the match. */
    private const CONTEXT = 70;

    public function __invoke(Request $request, ChatPageProps $page): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = trim((string) ($validated['q'] ?? ''));

        return Inertia::render('chat/search', [
            ...$page->for($user),
            'query' => $query,
            'results' => mb_strlen($query) >= 2 ? $this->search($user, $query) : null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function search(User $user, string $query): array
    {
        $like = '%'.addcslashes($query, '%_\\').'%';

        $conversations = $user->conversations()
            ->where(fn ($where) => $where
                ->where('title', 'like', $like)
                ->orWhereHas('messages', fn ($messages) => $messages->where('content', 'like', $like))
                ->orWhereHas('messages.attachments', fn ($attachments) => $attachments->where('original_name', 'like', $like)))
            ->orderByDesc('last_message_at')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'title', 'last_message_at']);

        return $conversations->map(function (Conversation $conversation) use ($like, $query): array {
            $content = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('content', 'like', $like)
                ->orderBy('created_at')
                ->value('content');

            return [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'last_message_at' => $conversation->last_message_at->toIso8601String(),
                'snippet' => is_string($content) ? self::snippet($content, $query) : null,
            ];
        })->values()->all();
    }

    /**
     * The text around the first match, split so the page can highlight it
     * without rendering HTML.
     *
     * @return array{before: string, match: string, after: string}
     */
    public static function snippet(string $content, string $query): array
    {
        $text = (string) preg_replace('/\s+/u', ' ', $content);
        $position = mb_stripos($text, $query);

        // The database matches without accents and case; fall back to the start.
        if ($position === false) {
            return ['before' => '', 'match' => '', 'after' => mb_substr($text, 0, self::CONTEXT * 2)];
        }

        $start = max(0, $position - self::CONTEXT);

        return [
            'before' => ($start > 0 ? '…' : '').mb_substr($text, $start, $position - $start),
            'match' => mb_substr($text, $position, mb_strlen($query)),
            'after' => mb_substr($text, $position + mb_strlen($query), self::CONTEXT).(mb_strlen($text) > $position + mb_strlen($query) + self::CONTEXT ? '…' : ''),
        ];
    }
}
