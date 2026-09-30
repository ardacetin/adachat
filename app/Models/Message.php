<?php

namespace App\Models;

use App\Domain\AI\Enums\MessageRole;
use App\Domain\Conversations\Enums\MessageStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $conversation_id
 * @property string|null $parent_message_id
 * @property MessageRole $role
 * @property string $content
 * @property MessageStatus $status
 * @property string|null $error_code
 * @property string|null $finish_reason
 * @property int|null $model_alias_id
 * @property int|null $ai_model_id
 * @property string|null $reservation_id
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Conversation $conversation
 * @property-read ModelAlias|null $modelAlias
 */
class Message extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @var array<string, string|null>
     */
    protected $attributes = [
        'content' => '',
        'error_code' => null,
        'finish_reason' => null,
        'metadata' => null,
    ];

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<ModelAlias, $this>
     */
    public function modelAlias(): BelongsTo
    {
        return $this->belongsTo(ModelAlias::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'status' => MessageStatus::class,
            'metadata' => 'array',
        ];
    }
}
