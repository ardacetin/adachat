<?php

namespace App\Domain\AI\Catalog;

use App\Domain\AI\Enums\ProviderDriver;
use App\Models\AiModel;
use Brick\Math\BigDecimal;
use JsonException;
use RuntimeException;

/**
 * The price catalog that ships with Ada (resources/catalog/models.json, or
 * the file in ada.catalog.path). It lets administrators add a known model
 * without typing prices; the prices come from the providers' official
 * pages, with their source and date.
 */
final class ModelCatalog
{
    /** @var list<CatalogModel>|null */
    private ?array $models = null;

    public function __construct(private readonly string $path) {}

    /**
     * @return list<CatalogModel>
     */
    public function all(): array
    {
        return $this->models ??= $this->load();
    }

    /**
     * @return list<CatalogModel>
     */
    public function forDriver(ProviderDriver $driver): array
    {
        return array_values(array_filter($this->all(), fn (CatalogModel $model) => $model->driver === $driver));
    }

    public function find(ProviderDriver $driver, string $id): ?CatalogModel
    {
        foreach ($this->all() as $model) {
            if ($model->driver === $driver && $model->id === $id) {
                return $model;
            }
        }

        return null;
    }

    /**
     * The catalog entry whose prices differ from a model added from the
     * catalog, after a release updated them; null when nothing changed.
     */
    public function newerPrices(AiModel $model): ?CatalogModel
    {
        if (($model->metadata['pricing_source'] ?? null) !== 'catalog') {
            return null;
        }

        $entry = $this->find($model->provider->driver, $model->provider_model_id);

        if ($entry === null) {
            return null;
        }

        foreach (['input_price_per_million', 'output_price_per_million', 'cached_input_price_per_million', 'cache_write_price_per_million'] as $column) {
            if (! self::samePrice($model->getAttribute($column), $entry->attributes()[$column])) {
                return $entry;
            }
        }

        return null;
    }

    private static function samePrice(mixed $stored, mixed $catalog): bool
    {
        if ($stored === null || $catalog === null) {
            return $stored === $catalog;
        }

        return BigDecimal::of((string) $stored)->isEqualTo((string) $catalog);
    }

    /**
     * @return list<CatalogModel>
     */
    private function load(): array
    {
        try {
            $data = json_decode((string) file_get_contents($this->path), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("The model catalog {$this->path} is not valid JSON.", previous: $exception);
        }

        $models = [];

        foreach ((array) ($data['models'] ?? []) as $entry) {
            $driver = ProviderDriver::tryFrom((string) ($entry['driver'] ?? ''));

            if (! is_array($entry) || $driver === null) {
                continue;
            }

            $prices = (array) ($entry['prices'] ?? []);

            $models[] = new CatalogModel(
                driver: $driver,
                id: (string) $entry['id'],
                name: (string) $entry['name'],
                contextWindow: (int) $entry['context_window'],
                maxOutputTokens: (int) $entry['max_output_tokens'],
                supportsVision: (bool) ($entry['supports_vision'] ?? false),
                supportsFiles: (bool) ($entry['supports_files'] ?? false),
                supportsReasoning: (bool) ($entry['supports_reasoning'] ?? false),
                inputPrice: (string) $prices['input'],
                outputPrice: (string) $prices['output'],
                cachedInputPrice: isset($prices['cached_input']) ? (string) $prices['cached_input'] : null,
                cacheWritePrice: isset($prices['cache_write']) ? (string) $prices['cache_write'] : null,
                source: (string) $entry['source'],
                asOf: (string) $entry['as_of'],
            );
        }

        return $models;
    }
}
