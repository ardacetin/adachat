<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AssistantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An institutional assistant: instructions written by the administrators on
 * top of a model alias, offered to chosen groups. Conversations started with
 * it keep its instructions and model.
 *
 * @property int $id
 * @property string $slug
 * @property array<string, string> $name Localized, keyed by locale.
 * @property array<string, string>|null $description
 * @property string $instructions
 * @property int $model_alias_id
 * @property list<string>|null $starter_prompts
 * @property string $icon
 * @property int $sort_order
 * @property bool $enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ModelAlias $modelAlias
 * @property-read Collection<int, AssistantDocument> $documents
 */
#[Fillable([
    'slug', 'name', 'description', 'instructions', 'model_alias_id',
    'starter_prompts', 'icon', 'sort_order', 'enabled',
])]
class Assistant extends Model
{
    /** @use HasFactory<AssistantFactory> */
    use HasFactory;

    /** Icons an administrator can choose (lucide names). */
    public const ICONS = [
        'sparkles', 'book-open', 'graduation-cap', 'scale', 'languages', 'code',
        'calculator', 'flask-conical', 'heart-pulse', 'briefcase', 'pen-line', 'life-buoy',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'description' => null,
        'starter_prompts' => null,
        'icon' => 'sparkles',
        'sort_order' => 0,
        'enabled' => true,
    ];

    /**
     * @return BelongsTo<ModelAlias, $this>
     */
    public function modelAlias(): BelongsTo
    {
        return $this->belongsTo(ModelAlias::class);
    }

    /**
     * @return BelongsToMany<Group, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class);
    }

    /**
     * @return HasMany<AssistantDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(AssistantDocument::class)->orderBy('sort_order')->orderBy('created_at');
    }

    /**
     * What the model is told: the instructions, then the documents' text,
     * each in a fence that its own content cannot close.
     */
    public function systemInstructions(): string
    {
        $text = trim($this->instructions);
        $documents = $this->relationLoaded('documents') ? $this->documents : $this->documents()->get();

        if ($documents->isEmpty()) {
            return $text;
        }

        $text .= "\n\n# Documents\n\nUse these documents provided by the institution when they are relevant.";

        foreach ($documents as $document) {
            $body = rtrim($document->extracted_text, "\n");
            preg_match_all('/`+/', $body, $runs);
            $fence = str_repeat('`', max(3, ...array_map(fn (string $run) => strlen($run) + 1, $runs[0] ?: [''])));
            $text .= "\n\n{$fence}{$document->original_name}\n{$body}\n{$fence}";
        }

        return $text;
    }

    public function localizedName(string $locale): string
    {
        return $this->name[$locale] ?? $this->name['en'] ?? (string) reset($this->name);
    }

    public function localizedDescription(string $locale): ?string
    {
        return $this->description[$locale] ?? $this->description['en'] ?? null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'starter_prompts' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
