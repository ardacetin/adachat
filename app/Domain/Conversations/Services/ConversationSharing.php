<?php

namespace App\Domain\Conversations\Services;

use App\Domain\AI\Enums\MessageRole;
use App\Domain\AI\Services\AliasAccess;
use App\Domain\Audit\AuditLogger;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Models\Conversation;
use App\Models\ConversationShare;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ModelAlias;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Read-only links to a conversation (docs/sharing.md). Sharing takes a
 * frozen copy of the active thread: messages written later are never shown,
 * attachments appear by name only and are never served to the viewer.
 */
final class ConversationSharing
{
    public function __construct(
        private readonly InstitutionSettings $settings,
        private readonly AliasAccess $aliases,
        private readonly AuditLogger $audit,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->conversation_sharing;
    }

    /**
     * Whether another link may be created: each stores the conversation again.
     */
    public function canShare(Conversation $conversation): bool
    {
        return ConversationShare::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('revoked_at')
            ->count() < (int) config('ada.sharing.max_links_per_conversation', 10);
    }

    /**
     * Counts a link or a copy against the user's daily allowance; false
     * when it is used up.
     */
    public function takeDailyAllowance(User $user): bool
    {
        $key = 'sharing-daily:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('ada.sharing.daily_limit', 50))) {
            return false;
        }

        RateLimiter::hit($key, 86400);

        return true;
    }

    /**
     * @return array{0: ConversationShare, 1: string} the share and its token, shown once
     */
    public function share(Conversation $conversation, User $owner): array
    {
        // 32 random bytes, base64url: 43 characters.
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $snapshot = $this->snapshot($conversation);

        $share = ConversationShare::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $owner->id,
            'token_hash' => ConversationShare::hashToken($token),
            'title' => $conversation->title,
            'snapshot' => $snapshot,
        ]);

        // Which conversation, never what it says.
        $this->audit->record('conversation.shared', $share, [], [
            'conversation_id' => $conversation->id,
            'messages' => count($snapshot['messages']),
        ]);

        return [$share, $token];
    }

    public function revoke(ConversationShare $share): void
    {
        if ($share->revoked_at !== null) {
            return;
        }

        // The copy goes with the link: a revoked link keeps only its record.
        $share->forceFill(['revoked_at' => CarbonImmutable::now(), 'snapshot' => ['model_alias_id' => null, 'messages' => []]])->save();

        $this->audit->record('conversation.share_revoked', $share, [], ['conversation_id' => $share->conversation_id]);
    }

    /**
     * The live share behind a link: not revoked, its conversation not
     * deleted, and sharing still allowed.
     */
    public function find(string $token): ?ConversationShare
    {
        if (! $this->enabled() || preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }

        return ConversationShare::query()
            ->where('token_hash', ConversationShare::hashToken($token))
            ->whereNull('revoked_at')
            ->whereHas('conversation')
            ->first();
    }

    public function recordView(ConversationShare $share, User $viewer): void
    {
        if ($viewer->id !== $share->user_id) {
            ConversationShare::query()->whereKey($share->id)->increment('view_count');
        }
    }

    /**
     * A new conversation of the viewer's with the shared messages. The
     * model is kept only where the viewer may use it; attachments and the
     * assistant are not copied.
     */
    public function copy(ConversationShare $share, User $viewer): Conversation
    {
        $snapshot = $share->snapshot;
        $allowed = fn (?int $aliasId): ?int => $aliasId !== null && $this->aliases->find($viewer, $aliasId) !== null ? $aliasId : null;

        return DB::transaction(function () use ($share, $viewer, $snapshot, $allowed): Conversation {
            $conversation = new Conversation;
            $conversation->forceFill([
                'user_id' => $viewer->id,
                'title' => $share->title,
                'model_alias_id' => $allowed($snapshot['model_alias_id']),
                'last_message_at' => CarbonImmutable::now(),
            ])->save();

            $parent = null;

            foreach ($snapshot['messages'] as $item) {
                $content = $item['content'];

                if ($content === '' && $item['attachments'] !== []) {
                    $content = implode(', ', $item['attachments']);
                }

                $message = new Message;
                $message->forceFill([
                    'conversation_id' => $conversation->id,
                    'parent_message_id' => $parent?->id,
                    'role' => MessageRole::from($item['role']),
                    'content' => $content,
                    'status' => MessageStatus::Completed,
                    'model_alias_id' => $item['role'] === MessageRole::Assistant->value ? $allowed($item['alias_id']) : null,
                    'metadata' => $item['sources'] === [] ? null : ['sources' => $item['sources']],
                ])->save();

                $parent = $message;
            }

            $this->audit->record('conversation.share_copied', $share, [], [
                'conversation_id' => $conversation->id,
                'messages' => count($snapshot['messages']),
            ]);

            return $conversation;
        });
    }

    /**
     * @return array{model_alias_id: int|null, messages: list<array{role: string, content: string, alias_id: int|null, alias: array<string, string>|null, attachments: list<string>, sources: list<array{url: string, title: string|null}>}>}
     */
    private function snapshot(Conversation $conversation): array
    {
        $thread = ConversationThread::active($conversation)->filter(fn (Message $message) => $message->role === MessageRole::User
            || ($message->content !== '' && in_array($message->status, [MessageStatus::Completed, MessageStatus::Cancelled], true)));

        $aliases = ModelAlias::query()->whereIn('id', $thread->pluck('model_alias_id')->filter()->unique())->get()->keyBy('id');
        $messages = [];

        foreach ($thread as $message) {
            $alias = $message->model_alias_id === null ? null : $aliases->get($message->model_alias_id);

            $messages[] = [
                'role' => $message->role->value,
                'content' => $message->content,
                'alias_id' => $message->model_alias_id,
                'alias' => $alias?->name,
                'attachments' => array_values($message->attachments->map(fn (MessageAttachment $attachment) => $attachment->original_name)->all()),
                'sources' => self::sources($message->metadata['sources'] ?? null),
            ];
        }

        return ['model_alias_id' => $conversation->model_alias_id, 'messages' => $messages];
    }

    /**
     * @return list<array{url: string, title: string|null}>
     */
    private static function sources(mixed $sources): array
    {
        if (! is_array($sources)) {
            return [];
        }

        $clean = [];

        foreach ($sources as $source) {
            if (is_array($source) && is_string($source['url'] ?? null)) {
                $clean[] = ['url' => $source['url'], 'title' => is_string($source['title'] ?? null) ? $source['title'] : null];
            }
        }

        return $clean;
    }
}
