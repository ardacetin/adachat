<?php

namespace App\Models;

use App\Domain\Attachments\Enums\AttachmentKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fixed document of an assistant. Only its extracted text is used: it is
 * added to the assistant's instructions with every message.
 *
 * @property string $id
 * @property int $assistant_id
 * @property AttachmentKind $kind
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $path
 * @property string $extracted_text
 * @property int|null $page_count
 * @property int $token_estimate
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property-read Assistant $assistant
 */
class AssistantDocument extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Assistant, $this>
     */
    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    /**
     * @return array<string, mixed> for the admin form
     */
    public function toAdmin(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'kind' => $this->kind->value,
            'size' => $this->size,
            'page_count' => $this->page_count,
            'token_estimate' => $this->token_estimate,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AttachmentKind::class,
        ];
    }
}
