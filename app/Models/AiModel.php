<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AiModelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A concrete provider model with pricing (USD per million tokens, decimal
 * strings — never floats) and capabilities. Admin-facing only.
 *
 * @property int $id
 * @property int $provider_id
 * @property string $provider_model_id
 * @property string $display_name
 * @property string|null $description
 * @property string $input_price_per_million
 * @property string $output_price_per_million
 * @property string|null $cached_input_price_per_million
 * @property string|null $cache_write_price_per_million
 * @property int $context_window
 * @property int $max_output_tokens
 * @property bool $supports_vision
 * @property bool $supports_files
 * @property bool $supports_tools
 * @property bool $supports_reasoning
 * @property bool $enabled
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Provider $provider
 */
#[Fillable([
    'provider_model_id', 'display_name', 'description',
    'input_price_per_million', 'output_price_per_million',
    'cached_input_price_per_million', 'cache_write_price_per_million',
    'context_window', 'max_output_tokens',
    'supports_vision', 'supports_files', 'supports_tools', 'supports_reasoning',
    'enabled',
])]
class AiModel extends Model
{
    /** @use HasFactory<AiModelFactory> */
    use HasFactory;

    /**
     * @var array<string, bool|null>
     */
    protected $attributes = [
        'description' => null,
        'cached_input_price_per_million' => null,
        'cache_write_price_per_million' => null,
        'supports_vision' => false,
        'supports_files' => false,
        'supports_tools' => false,
        'supports_reasoning' => false,
        'enabled' => true,
        'metadata' => null,
    ];

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * @return HasMany<ModelAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(ModelAlias::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_price_per_million' => 'decimal:6',
            'output_price_per_million' => 'decimal:6',
            'cached_input_price_per_million' => 'decimal:6',
            'cache_write_price_per_million' => 'decimal:6',
            'supports_vision' => 'boolean',
            'supports_files' => 'boolean',
            'supports_tools' => 'boolean',
            'supports_reasoning' => 'boolean',
            'enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
