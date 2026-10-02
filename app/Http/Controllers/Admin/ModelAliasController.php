<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ModelAliasRequest;
use App\Models\AiModel;
use App\Models\Group;
use App\Models\ModelAlias;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ModelAliasController extends Controller
{
    private const FIELDS = [
        'slug', 'name', 'description', 'ai_model_id', 'max_output_tokens',
        'temperature', 'system_prompt', 'show_model_details', 'web_search_enabled',
        'web_search_max_uses', 'sort_order', 'enabled',
    ];

    public function index(): Response
    {
        $aliases = ModelAlias::query()
            ->with('aiModel:id,display_name,max_output_tokens')
            ->orderBy('sort_order')
            ->orderBy('slug')
            ->get()
            ->map(fn (ModelAlias $alias) => [
                'id' => $alias->id,
                'slug' => $alias->slug,
                'name' => $alias->localizedName(app()->getLocale()),
                'model' => $alias->aiModel->display_name,
                'max_output_tokens' => $alias->effectiveMaxOutputTokens(),
                'enabled' => $alias->enabled,
            ]);

        return Inertia::render('admin/aliases/index', ['aliases' => $aliases]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(ModelAlias $alias): Response
    {
        return $this->form($alias);
    }

    public function store(ModelAliasRequest $request, AuditLogger $audit): RedirectResponse
    {
        $alias = ModelAlias::query()->create($this->values($request));

        // New aliases are available to the Default group unless chosen otherwise.
        $alias->groups()->sync($request->has('group_ids') ? $this->groupIds($request) : [Group::default()->id]);

        $audit->record('model_alias.created', $alias, [], [...$alias->only(self::FIELDS), 'group_ids' => $this->currentGroupIds($alias)]);

        return $this->saved();
    }

    public function update(ModelAliasRequest $request, ModelAlias $alias, AuditLogger $audit): RedirectResponse
    {
        $before = [...$alias->only(self::FIELDS), 'group_ids' => $this->currentGroupIds($alias)];

        $alias->fill($this->values($request))->save();

        if ($request->has('group_ids')) {
            $alias->groups()->sync($this->groupIds($request));
        }

        // Includes a change of the backing model and of the allowed groups.
        [$old, $new] = AuditLogger::diff($before, [...$alias->refresh()->only(self::FIELDS), 'group_ids' => $this->currentGroupIds($alias)]);

        if ($new !== []) {
            $audit->record('model_alias.updated', $alias, $old, $new);
        }

        return $this->saved();
    }

    /**
     * @return array<string, mixed>
     */
    private function values(ModelAliasRequest $request): array
    {
        $values = $request->safe()->only(self::FIELDS);
        $values['description'] = array_filter((array) ($values['description'] ?? [])) ?: null;

        return $values;
    }

    /**
     * @return list<int>
     */
    private function groupIds(ModelAliasRequest $request): array
    {
        return array_values(array_map('intval', (array) $request->validated('group_ids', [])));
    }

    /**
     * @return list<int>
     */
    private function currentGroupIds(ModelAlias $alias): array
    {
        return array_values(array_map(intval(...), $alias->groups()->orderBy('groups.id')->pluck('groups.id')->all()));
    }

    private function form(?ModelAlias $alias): Response
    {
        return Inertia::render('admin/aliases/form', [
            'alias' => $alias === null ? null : [...$alias->only(['id', ...self::FIELDS]), 'group_ids' => $this->currentGroupIds($alias)],
            'groups' => Group::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'models' => AiModel::query()
                ->with('provider:id,name')
                ->orderBy('display_name')
                ->get()
                ->map(fn (AiModel $model) => [
                    'id' => $model->id,
                    'label' => "{$model->display_name} ({$model->provider->name})",
                    'max_output_tokens' => $model->max_output_tokens,
                    // Search needs a model that can search and a search price.
                    'web_search' => $model->supports_web_search && $model->web_search_price_per_thousand !== null,
                ]),
            'webSearchMaxUsesLimit' => (int) config('ada.web_search.max_uses_limit', 5),
        ]);
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return to_route('admin.aliases.index');
    }
}
