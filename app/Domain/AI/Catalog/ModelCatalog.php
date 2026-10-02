<?php

namespace App\Domain\AI\Catalog;

use App\Domain\AI\Enums\ProviderDriver;
use App\Models\AiModel;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Date;
use JsonException;
use RuntimeException;

/**
 * The price catalog that ships with Ada (resources/catalog/models.json, or
 * the file in ada.catalog.path). It lets administrators add a known model
 * without typing prices; the prices come from the providers' official
 * pages, with their source and date.
 *
 * An entry can list price changes the provider has announced
 * ("price_changes": [{"from": "2027-01-01", "prices": {...}}]). From that
 * day (UTC) the new prices are the catalog's, so models added from the
 * catalog are flagged as having newer prices; they change only when an
 * administrator confirms.
 */
final class ModelCatalog
{
    /** @var list<CatalogModel>|null */
    private ?array $models = null;

    /** The day the models were loaded for; prices can change at midnight. */
    private ?string $loadedFor = null;

    public function __construct(private readonly string $path) {}

    /**
     * @return list<CatalogModel>
     */
    public function all(): array
    {
        $today = Date::now()->utc()->toDateString();

        if ($this->models === null || $this->loadedFor !== $today) {
            $this->models = $this->load($today);
            $this->loadedFor = $today;
        }

        return $this->models;
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
    private function load(string $today): array
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
            $next = null;
            $changes = (array) ($entry['price_changes'] ?? []);
            usort($changes, fn (mixed $a, mixed $b): int => strcmp((string) ($a['from'] ?? ''), (string) ($b['from'] ?? '')));

            foreach ($changes as $change) {
                $from = (string) ($change['from'] ?? '');

                if ($from <= $today) {
                    $prices = (array) ($change['prices'] ?? []);
                } elseif ($next === null) {
                    $next = ['from' => $from, ...self::prices((array) ($change['prices'] ?? []))];
                }
            }

            $prices = self::prices($prices);

            $models[] = new CatalogModel(
                driver: $driver,
                id: (string) $entry['id'],
                name: (string) $entry['name'],
                contextWindow: (int) $entry['context_window'],
                maxOutputTokens: (int) $entry['max_output_tokens'],
                supportsVision: (bool) ($entry['supports_vision'] ?? false),
                supportsFiles: (bool) ($entry['supports_files'] ?? false),
                supportsReasoning: (bool) ($entry['supports_reasoning'] ?? false),
                inputPrice: $prices['input'],
                outputPrice: $prices['output'],
                cachedInputPrice: $prices['cached_input'],
                cacheWritePrice: $prices['cache_write'],
                source: (string) $entry['source'],
                asOf: (string) $entry['as_of'],
                nextChange: $next,
            );
        }

        return $models;
    }

    /**
     * @param  array<mixed>  $prices
     * @return array{input: string, output: string, cached_input: string|null, cache_write: string|null}
     */
    private static function prices(array $prices): array
    {
        return [
            'input' => (string) ($prices['input'] ?? ''),
            'output' => (string) ($prices['output'] ?? ''),
            'cached_input' => isset($prices['cached_input']) ? (string) $prices['cached_input'] : null,
            'cache_write' => isset($prices['cache_write']) ? (string) $prices['cache_write'] : null,
        ];
    }
}
