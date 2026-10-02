<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AssistantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
