<?php

namespace App\Domain\AI\Catalog;

use App\Domain\AI\Enums\ProviderDriver;

/**
 * A model of the built-in price catalog (resources/catalog/models.json).
 */
final readonly class CatalogModel
{
    public function __construct(
        public ProviderDriver $driver,
        public string $id,
        public string $name,
        public int $contextWindow,
        public int $maxOutputTokens,
        public bool $supportsVision,
        public bool $supportsFiles,
        public bool $supportsReasoning,
        public string $inputPrice,
        public string $outputPrice,
        public ?string $cachedInputPrice,
        public ?string $cacheWritePrice,
        public string $source,
        public string $asOf,
    ) {}

    /**
     * The ai_models columns this entry sets.
     *
     * @return array<string, string|int|bool|null>
     */
    public function attributes(): array
    {
        return [
            'input_price_per_million' => $this->inputPrice,
            'output_price_per_million' => $this->outputPrice,
            'cached_input_price_per_million' => $this->cachedInputPrice,
            'cache_write_price_per_million' => $this->cacheWritePrice,
            'context_window' => $this->contextWindow,
            'max_output_tokens' => $this->maxOutputTokens,
            'supports_vision' => $this->supportsVision,
            'supports_files' => $this->supportsFiles,
            'supports_reasoning' => $this->supportsReasoning,
        ];
    }

    /**
     * @return array<string, mixed> for the admin model form
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver->value,
            'id' => $this->id,
            'name' => $this->name,
            'source' => $this->source,
            'as_of' => $this->asOf,
            ...$this->attributes(),
        ];
    }
}
