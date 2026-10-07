<?php

use App\Domain\Budget\Services\BudgetPeriods;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Models\AuditLog;
use App\Models\BudgetPeriod;
use App\Models\BudgetPolicy;
use App\Models\Group;
use App\Models\ModelAlias;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    updateSettings(InstitutionSettings::class, ['timezone' => 'Europe/Istanbul']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->admin = User::factory()->superAdmin()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function groupPayload(array $overrides = []): array
{
    return [
        'name' => 'Researchers',
        'description' => 'Staff with research projects',
        'budget_policy_id' => BudgetPolicy::factory()->create(['monthly_limit_usd' => '25'])->id,
        'requests_per_minute' => 30,
        'max_concurrent_streams' => 3,
        'alias_ids' => [],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function policyPayload(array $overrides = []): array
{
    return ['name' => 'Staff', 'monthly_limit_usd' => '15', ...$overrides];
}

test('only super admins manage budget policies', function (string $route) {
    $this->actingAs(User::factory()->admin()->create())->get(route($route))->assertForbidden();
    $this->actingAs($this->admin)->get(route($route))->assertOk();
})->with(['admin.budget-policies.index', 'admin.budget-policies.create']);

test('administrators manage groups; users do not', function (string $route) {
    $this->actingAs(User::factory()->create())->get(route($route))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route($route))->assertOk();
})->with(['admin.groups.index', 'admin.groups.create']);

test('an administrator can save a group', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.groups.store'), groupPayload())
        ->assertSessionHasNoErrors();

    expect(Group::query()->where('name', 'Researchers')->exists())->toBeTrue();
});

test('a group is created with its limits and models, and audited', function () {
    $aliases = ModelAlias::factory()->count(2)->create();

    $this->actingAs($this->admin)
        ->post(route('admin.groups.store'), groupPayload(['alias_ids' => $aliases->pluck('id')->all()]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.groups.index'));

    $group = Group::query()->where('name', 'Researchers')->sole();
    expect($group->requests_per_minute)->toBe(30)
        ->and($group->max_concurrent_streams)->toBe(3)
        ->and($group->is_default)->toBeFalse()
        ->and($group->modelAliases()->pluck('model_aliases.id')->sort()->values()->all())->toBe($aliases->pluck('id')->sort()->values()->all());

    $log = AuditLog::query()->where('action', 'group.created')->sole();
    expect($log->new_values['alias_ids'])->toBe($aliases->pluck('id')->sort()->values()->all());
});

test('group validation', function (array $overrides, string $error) {
    Group::factory()->create(['name' => 'Taken']);

    $this->actingAs($this->admin)
        ->post(route('admin.groups.store'), groupPayload($overrides))
        ->assertSessionHasErrors($error);
})->with([
    'duplicate name' => [['name' => 'Taken'], 'name'],
    'unknown policy' => [['budget_policy_id' => 999999], 'budget_policy_id'],
    'no requests' => [['requests_per_minute' => 0], 'requests_per_minute'],
    'too many streams' => [['max_concurrent_streams' => 21], 'max_concurrent_streams'],
    'unknown alias' => [['alias_ids' => [999999]], 'alias_ids.0'],
    'aliases missing' => [['alias_ids' => null], 'alias_ids'],
]);

test('identity provider groups are saved one per line, each in one group only', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.groups.store'), groupPayload(['idp_groups' => " staff@example.edu\nTeachers\n\nteachers ", 'idp_priority' => 5]))
        ->assertSessionHasNoErrors();

    $group = Group::query()->where('name', 'Researchers')->sole();
    expect($group->idp_groups)->toBe(['staff@example.edu', 'Teachers'])
        ->and($group->idp_priority)->toBe(5);

    $this->post(route('admin.groups.store'), groupPayload(['name' => 'Others', 'idp_groups' => 'TEACHERS']))
        ->assertSessionHasErrors('idp_groups.0');

    // Saving the group itself again is fine; clearing the list removes the mapping.
    $this->put(route('admin.groups.update', $group), groupPayload(['idp_groups' => 'teachers']))->assertSessionHasNoErrors();
    $this->put(route('admin.groups.update', $group), groupPayload(['idp_groups' => '']))->assertSessionHasNoErrors();
    expect($group->refresh()->idp_groups)->toBeNull();
});

test('changing a group\'s policy updates this month for members without an override', function () {
    $group = Group::factory()->create(['budget_policy_id' => BudgetPolicy::factory()->create(['monthly_limit_usd' => '10'])->id]);
    $member = User::factory()->create(['group_id' => $group->id]);
    $individual = User::factory()->create(['group_id' => $group->id, 'monthly_limit_override_usd' => '3']);
    $inactive = User::factory()->create(['group_id' => $group->id]);
    app(BudgetPeriods::class)->current($member);
    app(BudgetPeriods::class)->current($individual);

    $this->actingAs($this->admin)
        ->put(route('admin.groups.update', $group), groupPayload(['name' => $group->name, 'apply_to_current_period' => true]))
        ->assertSessionHasNoErrors();

    expect(BudgetPeriod::query()->where('user_id', $member->id)->sole()->limit_usd->toString())->toBe('25.0000000000')
        ->and(BudgetPeriod::query()->where('user_id', $individual->id)->sole()->limit_usd->toString())->toBe('3.0000000000')
        // No period is created for users who have not used Ada this month.
        ->and(BudgetPeriod::query()->where('user_id', $inactive->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'budget.limit_applied')->count())->toBe(1);
});

test('a policy change can wait for next month', function () {
    $group = Group::factory()->create(['budget_policy_id' => BudgetPolicy::factory()->create(['monthly_limit_usd' => '10'])->id]);
    $member = User::factory()->create(['group_id' => $group->id]);
    app(BudgetPeriods::class)->current($member);

    $this->actingAs($this->admin)
        ->put(route('admin.groups.update', $group), groupPayload(['name' => $group->name, 'apply_to_current_period' => false]))
        ->assertSessionHasNoErrors();

    expect(BudgetPeriod::query()->sole()->limit_usd->toString())->toBe('10.0000000000')
        ->and($group->refresh()->budgetPolicy->monthly_limit_usd->toString())->toBe('25.0000000000');
});

test('model access changes are audited', function () {
    $group = Group::factory()->create();
    $alias = ModelAlias::factory()->create();

    $this->actingAs($this->admin)
        ->put(route('admin.groups.update', $group), groupPayload([
            'name' => $group->name,
            'budget_policy_id' => $group->budget_policy_id,
            'requests_per_minute' => $group->requests_per_minute,
            'max_concurrent_streams' => $group->max_concurrent_streams,
            'description' => $group->description,
            'alias_ids' => [$alias->id],
        ]))
        ->assertSessionHasNoErrors();

    $log = AuditLog::query()->where('action', 'group.updated')->sole();
    expect($log->old_values)->toBe(['alias_ids' => []])
        ->and($log->new_values)->toBe(['alias_ids' => [$alias->id]]);
});

test('only empty, non-default groups can be deleted', function () {
    $empty = Group::factory()->create();
    $withMembers = Group::factory()->create();
    User::factory()->create(['group_id' => $withMembers->id]);

    $this->actingAs($this->admin)->delete(route('admin.groups.destroy', Group::default()))->assertRedirect();
    $this->actingAs($this->admin)->delete(route('admin.groups.destroy', $withMembers))->assertRedirect();
    $this->actingAs($this->admin)->delete(route('admin.groups.destroy', $empty))->assertRedirect(route('admin.groups.index'));

    expect(Group::query()->whereKey([Group::default()->id, $withMembers->id])->count())->toBe(2)
        ->and(Group::query()->whereKey($empty->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'group.deleted')->count())->toBe(1);
});

test('a budget policy is created and audited', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.budget-policies.store'), policyPayload(['monthly_limit_usd' => '12.50']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.budget-policies.index'));

    expect(BudgetPolicy::query()->where('name', 'Staff')->sole()->monthly_limit_usd->toString())->toBe('12.5000000000')
        ->and(AuditLog::query()->where('action', 'budget_policy.created')->sole()->new_values['monthly_limit_usd'])->toBe('12.5000000000');
});

test('budget policy validation', function (array $overrides, string $error) {
    BudgetPolicy::factory()->create(['name' => 'Taken']);

    $this->actingAs($this->admin)
        ->post(route('admin.budget-policies.store'), policyPayload($overrides))
        ->assertSessionHasErrors($error);
})->with([
    'duplicate name' => [['name' => 'Taken'], 'name'],
    'negative' => [['monthly_limit_usd' => '-1'], 'monthly_limit_usd'],
    'fractions of a cent' => [['monthly_limit_usd' => '1.001'], 'monthly_limit_usd'],
    'missing' => [['monthly_limit_usd' => ''], 'monthly_limit_usd'],
]);

test('a changed policy limit applies to this month for its members', function () {
    $policy = BudgetPolicy::factory()->create(['monthly_limit_usd' => '10']);
    $group = Group::factory()->create(['budget_policy_id' => $policy->id]);
    $member = User::factory()->create(['group_id' => $group->id]);
    $individual = User::factory()->create(['group_id' => $group->id, 'monthly_limit_override_usd' => '3']);
    $other = User::factory()->create();
    foreach ([$member, $individual, $other] as $user) {
        app(BudgetPeriods::class)->current($user);
    }
    $otherLimit = BudgetPeriod::query()->where('user_id', $other->id)->sole()->limit_usd->toString();

    $this->actingAs($this->admin)
        ->put(route('admin.budget-policies.update', $policy), policyPayload(['name' => $policy->name, 'monthly_limit_usd' => '40', 'apply_to_current_period' => true]))
        ->assertSessionHasNoErrors();

    expect(BudgetPeriod::query()->where('user_id', $member->id)->sole()->limit_usd->toString())->toBe('40.0000000000')
        ->and(BudgetPeriod::query()->where('user_id', $individual->id)->sole()->limit_usd->toString())->toBe('3.0000000000')
        ->and(BudgetPeriod::query()->where('user_id', $other->id)->sole()->limit_usd->toString())->toBe($otherLimit);

    $log = AuditLog::query()->where('action', 'budget_policy.updated')->sole();
    expect($log->old_values)->toBe(['monthly_limit_usd' => '10.0000000000'])
        ->and($log->new_values)->toBe(['monthly_limit_usd' => '40.0000000000']);
});

test('a renamed policy does not touch any period', function () {
    $policy = BudgetPolicy::factory()->create(['monthly_limit_usd' => '10']);
    $member = User::factory()->create(['group_id' => Group::factory()->create(['budget_policy_id' => $policy->id])->id]);
    app(BudgetPeriods::class)->current($member);

    $this->actingAs($this->admin)
        ->put(route('admin.budget-policies.update', $policy), policyPayload(['name' => 'Renamed', 'monthly_limit_usd' => '10']))
        ->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('action', 'budget.limit_applied')->exists())->toBeFalse();
});

test('a policy in use cannot be deleted', function () {
    $used = Group::default()->budgetPolicy;
    $unused = BudgetPolicy::factory()->create();

    $this->actingAs($this->admin)->delete(route('admin.budget-policies.destroy', $used))->assertRedirect();
    $this->actingAs($this->admin)->delete(route('admin.budget-policies.destroy', $unused))->assertRedirect();

    expect(BudgetPolicy::query()->whereKey($used->id)->exists())->toBeTrue()
        ->and(BudgetPolicy::query()->whereKey($unused->id)->exists())->toBeFalse();
});
