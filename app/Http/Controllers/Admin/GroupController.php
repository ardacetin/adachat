<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Budget\Services\CurrentLimits;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GroupRequest;
use App\Models\BudgetPolicy;
use App\Models\Group;
use App\Models\ModelAlias;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class GroupController extends Controller
{
    private const FIELDS = ['name', 'description', 'budget_policy_id', 'requests_per_minute', 'max_concurrent_streams'];

    public function index(): Response
    {
        $groups = Group::query()
            ->with('budgetPolicy')
            ->withCount(['users', 'modelAliases'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Group $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'is_default' => $group->is_default,
                'policy' => $group->budgetPolicy->name,
                'monthly_limit_usd' => $group->budgetPolicy->monthly_limit_usd->toString(),
                'requests_per_minute' => $group->requests_per_minute,
                'max_concurrent_streams' => $group->max_concurrent_streams,
                'users_count' => (int) $group->users_count,
                'aliases_count' => (int) $group->model_aliases_count,
            ]);

        return Inertia::render('admin/groups/index', ['groups' => $groups]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(Group $group): Response
    {
        return $this->form($group);
    }

    public function store(GroupRequest $request, AuditLogger $audit): RedirectResponse
    {
        $group = Group::query()->create($request->safe()->only(self::FIELDS));
        $group->modelAliases()->sync($this->aliasIds($request));

        $audit->record('group.created', $group, [], $this->audited($group));

        return $this->saved();
    }

    public function update(GroupRequest $request, Group $group, AuditLogger $audit, CurrentLimits $limits): RedirectResponse
    {
        $before = $this->audited($group);

        $group->fill($request->safe()->only(self::FIELDS))->save();
        $group->modelAliases()->sync($this->aliasIds($request));

        [$old, $new] = AuditLogger::diff($before, $this->audited($group->refresh()));

        if ($new !== []) {
            $audit->record('group.updated', $group, $old, $new);
        }

        if (array_key_exists('budget_policy_id', $new) && $request->boolean('apply_to_current_period', true)) {
            $limits->apply($group->users()->whereNull('monthly_limit_override_usd')->getQuery());
        }

        return $this->saved();
    }

    public function destroy(Group $group, AuditLogger $audit): RedirectResponse
    {
        if ($group->is_default || $group->users()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.group_not_deletable')]);

            return to_route('admin.groups.index');
        }

        $audit->record('group.deleted', $group, $this->audited($group));
        $group->modelAliases()->detach();
        $group->delete();

        return $this->saved();
    }

    /**
     * @return list<int>
     */
    private function aliasIds(GroupRequest $request): array
    {
        return array_values(array_map(intval(...), (array) $request->validated('alias_ids', [])));
    }

    /**
     * @return array<string, mixed>
     */
    private function audited(Group $group): array
    {
        return [
            ...$group->only(self::FIELDS),
            'alias_ids' => array_values(array_map(intval(...), $group->modelAliases()->orderBy('model_aliases.id')->pluck('model_aliases.id')->all())),
        ];
    }

    private function form(?Group $group): Response
    {
        return Inertia::render('admin/groups/form', [
            'group' => $group === null ? null : [
                'id' => $group->id,
                'is_default' => $group->is_default,
                'users_count' => $group->users()->count(),
                ...$this->audited($group),
            ],
            'policies' => BudgetPolicy::query()->orderBy('name')->get()->map(fn (BudgetPolicy $policy) => [
                'id' => $policy->id,
                'name' => $policy->name,
                'monthly_limit_usd' => $policy->monthly_limit_usd->toString(),
            ]),
            'aliases' => ModelAlias::query()->orderBy('sort_order')->orderBy('slug')->get()->map(fn (ModelAlias $alias) => [
                'id' => $alias->id,
                'name' => $alias->localizedName(app()->getLocale()),
                'slug' => $alias->slug,
                'enabled' => $alias->enabled,
            ]),
        ]);
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return to_route('admin.groups.index');
    }
}
