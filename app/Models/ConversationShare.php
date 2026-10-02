<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A read-only link to a frozen copy of a conversation (docs/sharing.md).
 * The link's token is never stored, only its SHA-256 hash.
 *
 * @property int $id
 * @property string $conversation_id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $title
 * @property array{model_alias_id: int|null, messages: list<array{role: string, content: string, alias_id: int|null, alias: array<string, string>|null, attachments: list<string>, sources: list<array{url: string, title: string|null}>}>} $snapshot
 * @property int $view_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $revoked_at
 * @property-read Conversation $conversation
 * @property-read User $user
 */
class ConversationShare extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var array<string, int>
     */
    protected $attributes = [
        'view_count' => 0,
    ];

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'revoked_at' => 'datetime',
        ];
    }
}
