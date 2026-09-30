<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Budget\Money\Usd;
use App\Domain\Budget\Services\BudgetEngine;
use App\Domain\Budget\Services\BudgetSummary;
use App\Domain\Budget\Services\EffectiveLimit;
use App\Domain\Budget\Services\PeriodCalculator;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\LastSuperAdmin;
use App\Domain\Identity\Services\UserAdministration;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Usage\Enums\UsageEventType;
use App\Domain\Usage\UsageReport;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\UsageEvent;
use App\Models\User;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The users screen. Administrators see accounts, budgets and usage figures,
 * never conversation content.
 */
class UserController extends Controller
{
    private const PER_PAGE = 25;

    private const SORTS = ['name', 'last_active', 'spent'];

    public function index(Request $request, InstitutionSettings $institution): Response
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'group_id' => ['nullable', 'integer'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'sort' => ['nullable', Rule::in(self::SORTS)],
        ]);

        [$start] = PeriodCalculator::monthContaining(CarbonImmutable::now(), $institution->timezone);
        $search = trim((string) ($filters['q'] ?? ''));

        $users = User::query()
            ->with('group.budgetPolicy')
            ->leftJoin('budget_periods as period', function ($join) use ($start): void {
                $join->on('period.user_id', '=', 'users.id')->where('period.period_start', '=', $start);
            })
            ->select('users.*', 'period.limit_usd as period_limit_usd', 'period.spent_usd as period_spent_usd', 'period.reserved_usd as period_reserved_usd')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('users.name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('users.email', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->when(isset($filters['group_id']), fn ($query) => $query->where('users.group_id', $filters['group_id']))
            ->when(isset($filters['role']), fn ($query) => $query->where('users.role', $filters['role']))
            ->when(isset($filters['status']), fn ($query) => $query->where('users.status', $filters['status']))
            ->when(($filters['sort'] ?? 'name') === 'name', fn ($query) => $query->orderBy('users.name'))
            ->when(($filters['sort'] ?? null) === 'last_active', fn ($query) => $query->orderByRaw('users.last_active_at IS NULL, users.last_active_at DESC'))
            ->when(($filters['sort'] ?? null) === 'spent', fn ($query) => $query->orderByRaw('COALESCE(period.spent_usd, 0) DESC'))
            ->orderBy('users.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $user) => $this->row($user));

        return Inertia::render('admin/users/index', [
            'users' => $users,
            'filters' => [
                'q' => $search,
                'group_id' => isset($filters['group_id']) ? (int) $filters['group_id'] : null,
                'role' => $filters['role'] ?? null,
                'status' => $filters['status'] ?? null,
                'sort' => $filters['sort'] ?? 'name',
            ],
            'groups' => Group::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, User $user, UsageReport $report): Response
    {
        Gate::authorize('view', $user);

        /** @var User $actor */
        $actor = $request->user();
        $user->load('group.budgetPolicy');

        $adjustments = UsageEvent::query()
            ->where('user_id', $user->id)
            ->where('type', UsageEventType::Adjustment)
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (UsageEvent $event) => [
                'id' => $event->id,
                'amount_usd' => BudgetSummary::cents($event->total_cost_usd, RoundingMode::HalfUp),
                'reason' => $event->reason,
                'by' => User::query()->whereKey($event->created_by)->value('name'),
                'created_at' => $event->created_at->toIso8601String(),
            ]);

        return Inertia::render('admin/users/show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'status' => $user->status->value,
                'group_id' => $user->group_id,
                'group' => $user->group->name,
                'policy_limit_usd' => BudgetSummary::cents($user->group->budgetPolicy->monthly_limit_usd, RoundingMode::Down),
                'override_usd' => $user->monthly_limit_override_usd === null ? null : BudgetSummary::cents($user->monthly_limit_override_usd, RoundingMode::Down),
                'created_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'last_active_at' => $user->last_active_at?->toIso8601String(),
                'is_self' => $actor->is($user),
            ],
            'usage' => $report->for($user, null, 'amount'),
            'adjustments' => $adjustments,
            'groups' => Group::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            // Not "can": that name is the shared prop with the actor's abilities.
            'permissions' => [
                'update' => $actor->can('update', $user),
                'changeStatus' => $actor->can('changeStatus', $user),
                'changeRole' => $actor->can('changeRole', $user),
                'adjustBudget' => $actor->can('adjustBudget', $user),
            ],
        ]);
    }

    public function updateGroup(Request $request, User $user, UserAdministration $users): RedirectResponse
    {
        Gate::authorize('update', $user);

        $validated = $request->validate([
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'apply_to_current_period' => ['boolean'],
        ]);

        $users->changeGroup($user, Group::query()->whereKey($validated['group_id'])->firstOrFail(), $request->boolean('apply_to_current_period', true));

        return $this->saved($user);
    }

    public function updateBudget(Request $request, User $user, UserAdministration $users): RedirectResponse
    {
        Gate::authorize('update', $user);

        $request->validate([
            // Empty: the group's policy applies.
            'monthly_limit_usd' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100000'],
            'apply_to_current_period' => ['boolean'],
        ]);

        $limit = $request->filled('monthly_limit_usd') ? Usd::of((string) $request->input('monthly_limit_usd')) : null;
        $users->setBudgetOverride($user, $limit, $request->boolean('apply_to_current_period', true));

        return $this->saved($user);
    }

    public function updateStatus(Request $request, User $user, UserAdministration $users): RedirectResponse
    {
        Gate::authorize('changeStatus', $user);

        $validated = $request->validate(['status' => ['required', Rule::enum(UserStatus::class)]]);

        try {
            $users->setStatus($user, UserStatus::from($validated['status']));
        } catch (LastSuperAdmin) {
            throw ValidationException::withMessages(['status' => __('admin.last_super_admin')]);
        }

        return $this->saved($user);
    }

    public function updateRole(Request $request, User $user, UserAdministration $users): RedirectResponse
    {
        Gate::authorize('changeRole', $user);

        $validated = $request->validate(['role' => ['required', Rule::enum(UserRole::class)]]);

        try {
            $users->changeRole($user, UserRole::from($validated['role']));
        } catch (LastSuperAdmin) {
            throw ValidationException::withMessages(['role' => __('admin.last_super_admin')]);
        }

        return $this->saved($user);
    }

    public function storeAdjustment(Request $request, User $user, BudgetEngine $engine): RedirectResponse
    {
        Gate::authorize('adjustBudget', $user);

        $validated = $request->validate([
            // Positive charges, negative credits.
            'amount_usd' => ['required', 'numeric', 'decimal:0,2', 'not_in:0', 'min:-100000', 'max:100000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $engine->adjust($user, Usd::of((string) $validated['amount_usd']), (string) $validated['reason'], $actor);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount_usd' => __('admin.credit_exceeds_spent')]);
        }

        return $this->saved($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(User $user): array
    {
        $periodLimit = $user->getAttribute('period_limit_usd');
        $limit = is_string($periodLimit) ? Usd::of($periodLimit) : EffectiveLimit::for($user);
        $spent = Usd::of((string) ($user->getAttribute('period_spent_usd') ?? '0'));
        $reserved = Usd::of((string) ($user->getAttribute('period_reserved_usd') ?? '0'));

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'group' => $user->group->name,
            'role' => $user->role->value,
            'status' => $user->status->value,
            'has_override' => $user->monthly_limit_override_usd !== null,
            'limit_usd' => BudgetSummary::cents($limit, RoundingMode::Down),
            'spent_usd' => BudgetSummary::cents($spent, RoundingMode::Up),
            'remaining_usd' => BudgetSummary::cents($limit->minus($spent)->minus($reserved)->max(Usd::zero()), RoundingMode::Down),
            'last_active_at' => $user->last_active_at?->toIso8601String(),
        ];
    }

    private function saved(User $user): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.saved')]);

        return to_route('admin.users.show', $user);
    }
}
