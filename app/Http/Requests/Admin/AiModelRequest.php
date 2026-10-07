<?php

namespace App\Http\Requests\Admin;

use App\Domain\AI\Catalog\CatalogModel;
use App\Domain\AI\Catalog\ModelCatalog;
use App\Models\AiModel;
use App\Models\Provider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Easy mode ("catalog"): the prices, context window, output cap and
 * capabilities come from the built-in catalog, never from the browser.
 * Advanced mode ("manual"): the administrator enters everything.
 */
class AiModelRequest extends FormRequest
{
    private const PRICE = ['numeric', 'min:0', 'max:99999999', 'decimal:0,6'];

    public function authorize(): bool
    {
        return $this->user()?->can('manage-system') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('pricing')) {
            $this->merge(['pricing' => 'manual']);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var AiModel|null $model */
        $model = $this->route('model');
        $manual = $this->input('pricing') !== 'catalog';
        $required = $manual ? 'required' : 'nullable';

        return [
            'pricing' => ['required', Rule::in(['catalog', 'manual'])],
            'provider_id' => ['required', 'integer', 'exists:providers,id'],
            'provider_model_id' => [
                'required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:\/-]+$/',
                Rule::unique('ai_models')->where('provider_id', $this->integer('provider_id'))->ignore($model?->id),
            ],
            'display_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'input_price_per_million' => [$required, ...self::PRICE],
            'output_price_per_million' => [$required, ...self::PRICE],
            'cached_input_price_per_million' => ['nullable', ...self::PRICE],
            'cache_write_price_per_million' => ['nullable', ...self::PRICE],
            'context_window' => [$required, 'integer', 'min:1', 'max:10000000'],
            'max_output_tokens' => [$required, 'integer', 'min:1', ...($manual ? ['lte:context_window'] : [])],
            'supports_vision' => [$required, 'boolean'],
            'supports_files' => [$required, 'boolean'],
            'supports_tools' => ['required', 'boolean'],
            'supports_reasoning' => [$required, 'boolean'],
            'supports_web_search' => ['sometimes', 'boolean'],
            'web_search_price_per_thousand' => ['nullable', 'required_if_accepted:supports_web_search', ...self::PRICE],
            'enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('pricing') === 'catalog' && ! $validator->errors()->has('provider_id') && $this->catalogModel() === null) {
                $validator->errors()->add('provider_model_id', __('admin.models.catalog_unknown'));
            }

            // OpenAI-compatible servers have no built-in search tool.
            if ($this->boolean('supports_web_search') && Provider::query()->find($this->integer('provider_id'))?->driver->searchesWeb() === false) {
                $validator->errors()->add('supports_web_search', __('admin.models.web_search_unsupported'));
            }
        }];
    }

    /**
     * The catalog entry for the chosen provider and model, in easy mode.
     */
    public function catalogModel(): ?CatalogModel
    {
        if ($this->input('pricing') !== 'catalog') {
            return null;
        }

        $provider = Provider::query()->find($this->integer('provider_id'));

        return $provider === null
            ? null
            : app(ModelCatalog::class)->find($provider->driver, (string) $this->input('provider_model_id'));
    }

    /**
     * The model attributes to store: what the administrator entered, with
     * the catalog's values in easy mode.
     *
     * @return array<string, mixed>
     */
    public function modelAttributes(): array
    {
        $attributes = $this->safe()->except(['provider_id', 'pricing']);
        $entry = $this->catalogModel();

        if ($entry !== null) {
            $attributes = [...$attributes, ...$entry->attributes()];
        }

        return $attributes;
    }

    /**
     * How the prices were set, kept in ai_models.metadata.
     *
     * @return array{pricing_source: string, catalog_as_of?: string}
     */
    public function pricingMetadata(): array
    {
        $entry = $this->catalogModel();

        return $entry === null
            ? ['pricing_source' => 'manual']
            : ['pricing_source' => 'catalog', 'catalog_as_of' => $entry->asOf];
    }
}
