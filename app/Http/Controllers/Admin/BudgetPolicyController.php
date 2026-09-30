<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Services\CurrentLimits;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BudgetPolicyRequest;
use App\Models\BudgetPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BudgetPolicyController extends Controller
{
    public function index(): Response
    {
        $policies = BudgetPolicy::query()
            ->withCount('groups')
            ->orderBy('name')
            ->get()
            ->map(fn (BudgetPolicy $policy) => [
                'id' => $policy->id,
                'name' => $policy->name,
                'monthly_limit_usd' => $policy->monthly_limit_usd->toString(),
                'groups_count' => (int) $policy->groups_count,
                'users_count' => self::members($policy)->count(),
            ]);

        return Inertia::render('admin/budget-policies/index', ['policies' => $policies]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/budget-policies/form', ['policy' => null]);
    }

    public function edit(BudgetPolicy $budgetPolicy): Response
    {
        return Inertia::render('admin/budget-policies/form', [
            'policy' => [
                'id' => $budgetPolicy->id,
                'name' => $budgetPolicy->name,
                'monthly_limit_usd' => $budgetPolicy->monthly_limit_usd->toString(),
                'users_count' => self::members($budgetPolicy)->count(),
                'groups_count' => $budgetPolicy->groups()->count(),
            ],
        ]);
    }

    public function store(BudgetPolicyRequest $request, AuditLogger $audit): RedirectResponse
    {
        $policy = BudgetPolicy::query()->create([
            'name' => $request->validated('name'),
            'monthly_limit_usd' => Usd::of((string) $request->validated('monthly_limit_usd')),
        ]);

        $audit->record('budget_policy.created', $policy, [], self::audited($policy));

        return $this->saved();
    }

    public function update(BudgetPolicyRequest $request, BudgetPolicy $budgetPolicy, AuditLogger $audit, CurrentLimits $limits): RedirectResponse
    {
        $before = self::audited($budgetPolicy);

        $budgetPolicy->fill([
            'name' => $request->validated('name'),
            'monthly_limit_usd' => Usd::of((string) $request->validated('monthly_limit_usd')),
        ])->save();

        [$old, $new] = AuditLogger::diff($before, self::audited($budgetPolicy));

        if ($new !== []) {
            $audit->record('budget_policy.updated', $budgetPolicy, $old, $new);
        }

        if (array_key_exists('monthly_limit_usd', $new) && $request->boolean('apply_to_current_period', true)) {
            $limits->apply(self::members($budgetPolicy));
        }

        return $this->saved();
    }

    public function destroy(BudgetPolicy $budgetPolicy, AuditLogger $audit): RedirectResponse
    {
        if ($budgetPolicy->groups()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.policy_in_use')]);

            return to_route('admin.budget-policies.index');
        }

        $audit->record('budget_policy.deleted', $budgetPolicy, self::audited($budgetPolicy));
        $budgetPolicy->delete();

        return $this->saved();
    }

    /**
     * Users whose limit comes from this policy (no individual override).
     *
     * @return Builder<User>
     */
    private static function members(BudgetPolicy $policy): Builder
    {
        return User::query()
            ->whereNull('monthly_limit_override_usd')
            ->whereHas('group', fn (Builder $query) => $query->where('budget_policy_id', $policy->id));
    }

    /**
     * @return array<string, string>
     */
    private static function audited(BudgetPolicy $policy): array
    {
        return ['name' => $policy->name, 'monthly_limit_usd' => $policy->monthly_limit_usd->toString()];
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return to_route('admin.budget-policies.index');
    }
}
