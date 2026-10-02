<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's thumbs up or down on an answer (docs/feedback.md). It carries no
 * user and no text; the alias, model and assistant are copied from the
 * message so the totals outlive it.
 *
 * @property int $id
 * @property string|null $message_id
 * @property int|null $model_alias_id
 * @property int|null $ai_model_id
 * @property int|null $assistant_id
 * @property string $rating "up" or "down"
 * @property string|null $reason Only for "down", one of REASONS.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class MessageFeedback extends Model
{
    public const REASONS = ['inaccurate', 'unhelpful', 'incomplete', 'too_long', 'other'];

    protected $table = 'message_feedback';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
