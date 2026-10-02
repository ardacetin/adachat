<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ModelAliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The model users choose ("Fast", "Advanced"). Its backing model can change
 * without affecting users or permissions.
 *
 * @property int $id
 * @property string $slug
 * @property array<string, string> $name Localized, keyed by locale.
 * @property array<string, string>|null $description
 * @property int $ai_model_id
 * @property int|null $max_output_tokens
 * @property string|null $temperature
 * @property string|null $system_prompt
 * @property bool $show_model_details
 * @property bool $web_search_enabled
 * @property int $web_search_max_uses Searches allowed per message.
 * @property int $sort_order
 * @property bool $enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AiModel $aiModel
 */
#[Fillable([
    'slug', 'name', 'description', 'ai_model_id', 'max_output_tokens',
    'temperature', 'system_prompt', 'show_model_details', 'web_search_enabled',
    'web_search_max_uses', 'sort_order', 'enabled',
])]
class ModelAlias extends Model
{
    /** @use HasFactory<ModelAliasFactory> */
    use HasFactory;

    /**
     * @var array<string, bool|int|null>
     */
    protected $attributes = [
        'description' => null,
        'max_output_tokens' => null,
        'temperature' => null,
        'system_prompt' => null,
        'show_model_details' => false,
        'web_search_enabled' => false,
        'web_search_max_uses' => 3,
        'sort_order' => 0,
        'enabled' => true,
    ];

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    /**
     * @return BelongsToMany<Group, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class);
    }

    /**
     * Name in the given locale, falling back to English, then any value.
     */
    public function localizedName(string $locale): string
    {
        return $this->name[$locale] ?? $this->name['en'] ?? (string) reset($this->name);
    }

    /**
     * Output cap sent to the provider: the alias cap within the model maximum.
     */
    public function effectiveMaxOutputTokens(): int
    {
        return min($this->max_output_tokens ?? PHP_INT_MAX, $this->aiModel->max_output_tokens);
    }

    /**
     * Searches per message when users may search with this alias, null
     * otherwise: the alias must allow it and its model must support and
     * price it.
     */
    public function webSearchMaxUses(): ?int
    {
        $model = $this->aiModel;

        if (! $this->web_search_enabled || ! $model->supports_web_search || $model->web_search_price_per_thousand === null) {
            return null;
        }

        return max(1, min($this->web_search_max_uses, (int) config('ada.web_search.max_uses_limit', 5)));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'temperature' => 'decimal:2',
            'show_model_details' => 'boolean',
            'web_search_enabled' => 'boolean',
            'web_search_max_uses' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}
