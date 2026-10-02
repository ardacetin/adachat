<?php

namespace App\Http\Controllers\Chat;

use App\Domain\AI\Enums\MessageRole;
use App\Domain\Conversations\Services\ConversationThread;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ModelAlias;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A conversation as a Markdown file, for its owner only. The answers are
 * Markdown already and are written as they are.
 */
class ConversationExportController extends Controller
{
    public function __invoke(Conversation $conversation): Response
    {
        Gate::authorize('view', $conversation);

        $locale = app()->getLocale();
        $title = $conversation->title ?? __('chat.export.untitled');
        $lines = [
            '# '.$title,
            '',
            '_'.__('chat.export.exported', ['date' => now()->toDateString()]).'_',
        ];

        $thread = ConversationThread::active($conversation);
        $aliases = ModelAlias::query()->whereIn('id', $thread->pluck('model_alias_id')->filter())->get()->keyBy('id');

        foreach ($thread as $message) {
            /** @var Message $message */
            $heading = $message->role === MessageRole::User
                ? __('chat.export.you')
                : __('chat.export.assistant', ['model' => $aliases->get($message->model_alias_id)?->localizedName($locale) ?? 'Ada']);

            $lines[] = '';
            $lines[] = '## '.$heading;
            $lines[] = '';
            $lines[] = rtrim($message->content);

            $names = $message->attachments->map(fn (MessageAttachment $attachment) => $attachment->original_name);

            if ($names->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '_'.__('chat.export.attachments', ['names' => $names->implode(', ')]).'_';
            }
        }

        $filename = 'ada-'.(Str::slug($title) ?: 'conversation').'-'.now()->toDateString().'.md';

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
