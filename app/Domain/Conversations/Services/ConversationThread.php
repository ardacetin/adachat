<?php

namespace App\Domain\Conversations\Services;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * Messages form a tree (regenerating an answer adds a sibling). The active
 * thread is the path from the newest message back to the first one.
 */
final class ConversationThread
{
    /**
     * @return Collection<int, Message> oldest first
     */
    public static function active(Conversation $conversation): Collection
    {
        /** @var Collection<string, Message> $messages */
        $messages = $conversation->messages()->with('attachments')->orderBy('id')->get()->keyBy('id');

        $thread = [];
        $current = $messages->last();

        while ($current !== null) {
            $thread[] = $current;
            $current = $current->parent_message_id !== null ? $messages->get($current->parent_message_id) : null;
        }

        return collect(array_reverse($thread));
    }
}
