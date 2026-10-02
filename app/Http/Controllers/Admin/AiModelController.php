<?php

namespace App\Http\Controllers\Admin;

use App\Domain\AI\Catalog\CatalogModel;
use App\Domain\AI\Catalog\ModelCatalog;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiModelRequest;
use App\Models\AiModel;
use App\Models\Group;
use App\Models\Provider;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AiModelController extends Controller
{
    private const FIELDS = [
        'provider_id', 'provider_model_id', 'display_name', 'description',
        'input_price_per_million', 'output_price_per_million',
        'cached_input_price_per_million', 'cache_write_price_per_million',
        'context_window', 'max_output_tokens',
        'supports_vision', 'supports_files', 'supports_tools', 'supports_reasoning', 'enabled',
    ];

    public function index(ModelCatalog $catalog): Response
    {
        $models = AiModel::query()
            ->with('provider:id,name,driver')
            ->orderBy('provider_id')
            ->orderBy('display_name')
            ->get()
            ->map(fn (AiModel $model) => [
                ...$model->only(['id', 'provider_model_id', 'display_name', 'input_price_per_million', 'output_price_per_million', 'context_window', 'enabled']),
                'provider' => $model->provider->name,
                'pricing_source' => $model->metadata['pricing_source'] ?? 'manual',
                // A newer release changed this model's catalog prices.
                'catalog_update' => $catalog->newerPrices($model)?->toArray(),
            ]);

        return Inertia::render('admin/models/index', ['models' => $models]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(AiModel $model): Response
    {
        return $this->form($model);
    }

    public function store(AiModelRequest $request, AuditLogger $audit): RedirectResponse
    {
        $model = new AiModel($request->modelAttributes());
        $model->provider_id = $request->integer('provider_id');
        $model->metadata = $request->pricingMetadata();
        $model->save();

        $audit->record('ai_model.created', $model, [], $model->only(self::FIELDS));

        return $this->saved();
    }

    public function update(AiModelRequest $request, AiModel $model, AuditLogger $audit): RedirectResponse
    {
        $before = $model->only(self::FIELDS);

        $model->fill($request->modelAttributes());
        $model->provider_id = $request->integer('provider_id');
        $model->metadata = [...($model->metadata ?? []), ...$request->pricingMetadata()];

        if ($model->metadata['pricing_source'] === 'manual') {
            $model->metadata = array_diff_key($model->metadata, ['catalog_as_of' => true]);
        }

        $model->save();

        // Price changes are recorded with old and new values.
        [$old, $new] = AuditLogger::diff($before, $model->refresh()->only(self::FIELDS));

        if ($new !== []) {
            $audit->record('ai_model.updated', $model, $old, $new);
        }

        return $this->saved();
    }

    /**
     * Take over the catalog's current prices for a model added from it.
     */
    public function syncCatalog(AiModel $model, ModelCatalog $catalog, AuditLogger $audit): RedirectResponse
    {
        $entry = $catalog->newerPrices($model);
        abort_if($entry === null, 409);

        $before = $model->only(self::FIELDS);
        $model->fill($entry->attributes());
        $model->metadata = [...($model->metadata ?? []), 'catalog_as_of' => $entry->asOf];
        $model->save();

        [$old, $new] = AuditLogger::diff($before, $model->refresh()->only(self::FIELDS));
        $audit->record('ai_model.updated', $model, $old, $new);

        return $this->saved();
    }

    private function form(?AiModel $model): Response
    {
        $catalog = app(ModelCatalog::class);

        return Inertia::render('admin/models/form', [
            'model' => $model === null ? null : [
                ...$model->only(['id', ...self::FIELDS]),
                'pricing' => $model->metadata['pricing_source'] ?? 'manual',
            ],
            'providers' => Provider::query()->orderBy('name')->get(['id', 'name', 'driver'])->map(fn (Provider $provider) => [
                'id' => $provider->id,
                'name' => $provider->name,
                'driver' => $provider->driver->value,
                'catalog' => array_map(fn (CatalogModel $entry) => $entry->toArray(), $catalog->forDriver($provider->driver)),
            ]),
            // Used by the cost hints ("a user with this budget can send …").
            'exampleBudgetUsd' => Group::default()->budgetPolicy->monthly_limit_usd->toString(),
        ]);
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return to_route('admin.models.index');
    }
}
