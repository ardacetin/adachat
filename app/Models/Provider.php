<?php

namespace App\Models;

use App\Domain\AI\Enums\ProviderDriver;
use Carbon\CarbonImmutable;
use Database\Factories\ProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A configured AI provider account. Holds no secrets itself.
 *
 * @property int $id
 * @property string $slug
 * @property ProviderDriver $driver
 * @property string $name
 * @property string|null $base_url
 * @property array<string, mixed>|null $options
 * @property bool $enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ProviderCredential|null $activeCredential
 * @property-read int|null $models_count
 */
#[Fillable(['slug', 'driver', 'name', 'base_url', 'enabled'])]
class Provider extends Model
{
    /** @use HasFactory<ProviderFactory> */
    use HasFactory;

    /**
     * @var array<string, bool|null>
     */
    protected $attributes = [
        'base_url' => null,
        'options' => null,
        'enabled' => true,
    ];

    /**
     * @return HasMany<ProviderCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(ProviderCredential::class);
    }

    /**
     * @return HasOne<ProviderCredential, $this>
     */
    public function activeCredential(): HasOne
    {
        return $this->hasOne(ProviderCredential::class)->where('is_active', true)->latestOfMany();
    }

    /**
     * @return HasMany<AiModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }

    /**
     * Whether the .env key of the driver may be sent here: only to the
     * driver's own address. OpenAI-compatible servers have none, so their
     * .env key follows the configured address (ProviderRequest requires a
     * key of its own for any address set in the panel while it exists).
     */
    public function usesEnvKeyAddress(): bool
    {
        $default = $this->driver->defaultBaseUrl();

        return $default === '' || $this->baseUrl() === rtrim($default, '/');
    }

    public function baseUrl(): string
    {
        return rtrim($this->base_url ?? $this->driver->defaultBaseUrl(), '/');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => ProviderDriver::class,
            'options' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
