<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A user's chat. Only its owner can read it — administrators included.
 *
 * @property string $id
 * @property int $user_id
 * @property string|null $title
 * @property int|null $model_alias_id
 * @property CarbonImmutable $last_message_at
 * @property CarbonImmutable|null $pinned_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read User $user
 * @property-read ModelAlias|null $modelAlias
 */
#[Fillable(['title', 'model_alias_id'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @var array<string, null>
     */
    protected $attributes = [
        'title' => null,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ModelAlias, $this>
     */
    public function modelAlias(): BelongsTo
    {
        return $this->belongsTo(ModelAlias::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'pinned_at' => 'datetime',
        ];
    }
}
