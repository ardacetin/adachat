<?php

namespace App\Http\Controllers\Chat;

use App\Domain\AI\Enums\MessageRole;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageFeedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Thumbs up / down on one of the user's own answers; an empty rating takes
 * the vote back. A reason is kept only with a thumbs down.
 */
class MessageFeedbackController extends Controller
{
    public function __invoke(Request $request, Message $message): RedirectResponse
    {
        Gate::authorize('update', $message->conversation);
        abort_unless($message->role === MessageRole::Assistant && $message->status === MessageStatus::Completed, 422);

        $validated = $request->validate([
            'rating' => ['nullable', Rule::in(['up', 'down'])],
            'reason' => ['nullable', 'required_if:rating,down', Rule::in(MessageFeedback::REASONS)],
        ]);

        $rating = $validated['rating'] ?? null;

        if ($rating === null) {
            MessageFeedback::query()->where('message_id', $message->id)->delete();
        } else {
            MessageFeedback::query()->updateOrCreate(['message_id' => $message->id], [
                'rating' => $rating,
                'reason' => $rating === 'down' ? $validated['reason'] : null,
                'model_alias_id' => $message->model_alias_id,
                'ai_model_id' => $message->ai_model_id,
                'assistant_id' => $message->conversation->assistant_id,
            ]);
        }

        return back();
    }
}
