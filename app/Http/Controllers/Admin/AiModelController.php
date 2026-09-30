<?php

namespace App\Http\Controllers\Admin;

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

    public function index(): Response
    {
        $models = AiModel::query()
            ->with('provider:id,name')
            ->orderBy('provider_id')
            ->orderBy('display_name')
            ->get()
            ->map(fn (AiModel $model) => [
                ...$model->only(['id', 'provider_model_id', 'display_name', 'input_price_per_million', 'output_price_per_million', 'context_window', 'enabled']),
                'provider' => $model->provider->name,
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
        $model = new AiModel($request->safe()->except('provider_id'));
        $model->provider_id = $request->integer('provider_id');
        $model->save();

        $audit->record('ai_model.created', $model, [], $model->only(self::FIELDS));

        return $this->saved();
    }

    public function update(AiModelRequest $request, AiModel $model, AuditLogger $audit): RedirectResponse
    {
        $before = $model->only(self::FIELDS);

        $model->fill($request->safe()->except('provider_id'));
        $model->provider_id = $request->integer('provider_id');
        $model->save();

        // Price changes are recorded with old and new values.
        [$old, $new] = AuditLogger::diff($before, $model->refresh()->only(self::FIELDS));

        if ($new !== []) {
            $audit->record('ai_model.updated', $model, $old, $new);
        }

        return $this->saved();
    }

    private function form(?AiModel $model): Response
    {
        return Inertia::render('admin/models/form', [
            'model' => $model?->only(['id', ...self::FIELDS]),
            'providers' => Provider::query()->orderBy('name')->get(['id', 'name']),
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
