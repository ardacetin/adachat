<?php

namespace App\Models;

use App\Domain\Attachments\Enums\AttachmentKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $message_id
 * @property AttachmentKind $kind
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $path
 * @property string|null $extracted_text
 * @property int $token_estimate
 * @property int|null $width
 * @property int|null $height
 * @property CarbonImmutable|null $created_at
 * @property-read User $user
 * @property-read Message|null $message
 */
class MessageAttachment extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @var list<string>
     */
    protected $hidden = ['path', 'extracted_text', 'sha256'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Uploaded but not sent yet.
     *
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('message_id');
    }

    public function isPending(): bool
    {
        return $this->message_id === null;
    }

    /**
     * The fields the chat UI needs; never the path or content.
     *
     * @return array{id: string, kind: string, name: string, size: int, mime: string, token_estimate: int, url: string}
     */
    public function toClient(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'name' => $this->original_name,
            'size' => $this->size,
            'mime' => $this->mime,
            'token_estimate' => $this->token_estimate,
            'url' => route('attachments.show', $this),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AttachmentKind::class,
            'size' => 'integer',
            'token_estimate' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }
}
