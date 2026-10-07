<?php

use App\Domain\Budget\Services\BudgetPeriods;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\LastSuperAdmin;
use App\Domain\Identity\Services\UserAdministration;
use App\Domain\Institution\Settings\AuthSettings;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Models\AuditLog;
use App\Models\BudgetPeriod;
use App\Models\BudgetPolicy;
use App\Models\Conversation;
use App\Models\Group;
use App\Models\UsageEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    updateSettings(InstitutionSettings::class, ['timezone' => 'Europe/Istanbul', 'budget_display' => 'percent']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->superAdmin = User::factory()->superAdmin()->create(['name' => 'Root']);
    $this->admin = User::factory()->admin()->create(['name' => 'Helper']);
});

function member(string $limit = '10', array $attributes = []): User
{
    $group = Group::factory()->create(['budget_policy_id' => BudgetPolicy::factory()->create(['monthly_limit_usd' => $limit])->id]);

    return User::factory()->create(['group_id' => $group->id, ...$attributes]);
}

test('users cannot open the users screen', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('admin.audit-log.index'))->assertForbidden();
});

test('the list shows budgets of this month and filters', function () {
    $ada = member('10', ['name' => 'Ada Lovelace', 'email' => 'ada@example.edu']);
    member('10', ['name' => 'Grace Hopper', 'email' => 'grace@example.edu', 'status' => UserStatus::Disabled]);
    app(BudgetPeriods::class)->current($ada);
    BudgetPeriod::query()->where('user_id', $ada->id)->update(['spent_usd' => '2.5']);

    $this->actingAs($this->admin)->get(route('admin.users.index', ['q' => 'lovelace']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/index')
            ->where('users.total', 1)
            ->where('users.data.0.email', 'ada@example.edu')
            ->where('users.data.0.limit_usd', '10.00')
            ->where('users.data.0.spent_usd', '2.50')
            ->where('users.data.0.remaining_usd', '7.50'));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['status' => 'disabled']))
        ->assertInertia(fn ($page) => $page->where('users.total', 1)->where('users.data.0.email', 'grace@example.edu'));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['role' => 'super_admin']))
        ->assertInertia(fn ($page) => $page->where('users.total', 1)->where('users.data.0.name', 'Root'));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['sort' => 'spent']))
        ->assertInertia(fn ($page) => $page->where('users.data.0.email', 'ada@example.edu'));
});

test('search terms are not wildcards', function () {
    member('10', ['name' => 'Ada']);

    $this->actingAs($this->admin)->get(route('admin.users.index', ['q' => '%']))
        ->assertInertia(fn ($page) => $page->where('users.total', 0));
});

test('the detail page shows usage in dollars but never conversations', function () {
    $user = member('10');
    Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Secret research plan']);

    $response = $this->actingAs($this->admin)->get(route('admin.users.show', $user))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/show')
            // Administrators see amounts even with the "percent" display.
            ->where('usage.summary.display', 'amount')
            ->where('usage.summary.limit_usd', '10.00')
            ->where('permissions.update', true)
            ->where('permissions.changeRole', false)
            ->where('permissions.adjustBudget', false)
            ->where('can.accessAdmin', true));

    expect($response->getContent())->not->toContain('Secret research plan');
});

test('an administrator changes the group and it applies to this month', function () {
    $user = member('10');
    app(BudgetPeriods::class)->current($user);
    $target = Group::factory()->create(['budget_policy_id' => BudgetPolicy::factory()->create(['monthly_limit_usd' => '30'])->id]);

    $this->actingAs($this->admin)
        ->put(route('admin.users.group', $user), ['group_id' => $target->id, 'apply_to_current_period' => true])
        ->assertRedirect(route('admin.users.show', $user));

    expect($user->refresh()->group_id)->toBe($target->id)
        ->and(BudgetPeriod::query()->where('user_id', $user->id)->sole()->limit_usd->toString())->toBe('30.0000000000')
        ->and(AuditLog::query()->where('action', 'user.group_changed')->sole()->new_values)->toBe(['group_id' => $target->id]);
});

test('while group mapping is on, a group set by hand is pinned unless the administrator says otherwise', function () {
    updateSettings(AuthSettings::class, ['group_mapping' => true]);
    $user = member('10');
    $target = Group::factory()->create();

    $this->actingAs($this->admin)->put(route('admin.users.group', $user), ['group_id' => $target->id]);
    expect($user->refresh()->group_pinned)->toBeTrue();

    // Handing the group back to the identity provider.
    $this->put(route('admin.users.group', $user), ['group_id' => $target->id, 'pinned' => false]);
    expect($user->refresh()->group_pinned)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'user.group_changed')->latest('id')->first()->new_values)->toBe(['group_pinned' => false]);

    $this->get(route('admin.users.show', $user))->assertInertia(fn ($page) => $page
        ->where('group_mapping', true)
        ->where('user.group_pinned', false)
        ->where('user.idp_groups', null));
});

test('an administrator sets and clears an individual budget without a cap', function () {
    $user = member('10');
    app(BudgetPeriods::class)->current($user);

    $this->actingAs($this->admin)
        ->put(route('admin.users.budget', $user), ['monthly_limit_usd' => '500', 'apply_to_current_period' => true])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->monthly_limit_override_usd?->toString())->toBe('500.0000000000')
        ->and(BudgetPeriod::query()->where('user_id', $user->id)->sole()->limit_usd->toString())->toBe('500.0000000000');

    $this->actingAs($this->admin)
        ->put(route('admin.users.budget', $user), ['monthly_limit_usd' => '', 'apply_to_current_period' => false])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->monthly_limit_override_usd)->toBeNull()
        // Not applied: this month keeps the individual limit.
        ->and(BudgetPeriod::query()->where('user_id', $user->id)->sole()->limit_usd->toString())->toBe('500.0000000000')
        ->and(AuditLog::query()->where('action', 'user.budget_override_changed')->count())->toBe(2);
});

test('budget input is validated', function (mixed $value) {
    $this->actingAs($this->admin)
        ->put(route('admin.users.budget', member()), ['monthly_limit_usd' => $value])
        ->assertSessionHasErrors('monthly_limit_usd');
})->with(['-1', '1.001', 'lots', '100001']);

test('disabling signs the user out everywhere and can be undone', function () {
    config(['session.driver' => 'database']);
    $user = member();
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()]);
    $token = $user->remember_token;

    $this->actingAs($this->admin)
        ->put(route('admin.users.status', $user), ['status' => 'disabled'])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->status)->toBe(UserStatus::Disabled)
        ->and($user->disabled_at)->not->toBeNull()
        ->and($user->remember_token)->not->toBe($token)
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();

    $this->actingAs($user)->get(route('home'))->assertRedirect(route('login'));

    $this->actingAs($this->admin)->put(route('admin.users.status', $user), ['status' => 'active'])->assertSessionHasNoErrors();
    expect($user->refresh()->status)->toBe(UserStatus::Active)
        ->and($user->disabled_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'user.status_changed')->count())->toBe(2);
});

test('nobody changes their own status or role', function () {
    $this->actingAs($this->superAdmin)->put(route('admin.users.status', $this->superAdmin), ['status' => 'disabled'])->assertForbidden();
    $this->actingAs($this->superAdmin)->put(route('admin.users.role', $this->superAdmin), ['role' => 'user'])->assertForbidden();
});

test('administrators do not change their own group or budget', function () {
    $group = Group::factory()->create();

    $this->actingAs($this->admin)->put(route('admin.users.budget', $this->admin), ['monthly_limit_usd' => '100000'])->assertForbidden();
    $this->actingAs($this->admin)->put(route('admin.users.group', $this->admin), ['group_id' => $group->id])->assertForbidden();

    // A super administrator may set their own.
    $this->actingAs($this->superAdmin)->put(route('admin.users.group', $this->superAdmin), ['group_id' => $group->id])->assertSessionHasNoErrors();

    expect($this->admin->refresh()->group_id)->not->toBe($group->id)
        ->and($this->superAdmin->refresh()->group_id)->toBe($group->id);
});

test('administrators do not change their own group or a super administrator\'s through the group', function () {
    $small = BudgetPolicy::factory()->create(['monthly_limit_usd' => '1']);
    $generous = BudgetPolicy::factory()->create(['monthly_limit_usd' => '1000']);
    $own = Group::factory()->create(['budget_policy_id' => $small->id]);
    $this->admin->forceFill(['group_id' => $own->id])->save();
    $payload = fn (Group $group) => ['name' => $group->name, 'budget_policy_id' => $generous->id, 'requests_per_minute' => 1000, 'max_concurrent_streams' => 20, 'alias_ids' => []];

    $this->actingAs($this->admin)->put(route('admin.groups.update', $own), $payload($own))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.groups.edit', $own))
        ->assertInertia(fn ($page) => $page->where('group.reserved', true));

    $withSuper = Group::factory()->create(['budget_policy_id' => $small->id]);
    $this->superAdmin->forceFill(['group_id' => $withSuper->id])->save();
    $this->actingAs($this->admin)->put(route('admin.groups.update', $withSuper), $payload($withSuper))->assertForbidden();

    // Other groups stay the administrators' to manage; super administrators manage all.
    $other = Group::factory()->create(['budget_policy_id' => $small->id]);
    member()->forceFill(['group_id' => $other->id])->save();
    $this->actingAs($this->admin)->put(route('admin.groups.update', $other), $payload($other))->assertSessionHasNoErrors();
    $this->actingAs($this->superAdmin)->put(route('admin.groups.update', $own), $payload($own))->assertSessionHasNoErrors();

    expect($own->refresh()->budget_policy_id)->toBe($generous->id)
        ->and($withSuper->refresh()->budget_policy_id)->toBe($small->id);
});

test('administrators cannot touch super administrators, roles or adjustments', function () {
    $user = member();

    $this->actingAs($this->admin)->put(route('admin.users.status', $this->superAdmin), ['status' => 'disabled'])->assertForbidden();
    $this->actingAs($this->admin)->put(route('admin.users.budget', $this->superAdmin), ['monthly_limit_usd' => '1'])->assertForbidden();
    $this->actingAs($this->admin)->put(route('admin.users.role', $user), ['role' => 'super_admin'])->assertForbidden();
    $this->actingAs($this->admin)->post(route('admin.users.adjustments', $user), ['amount_usd' => '1', 'reason' => 'x'])->assertForbidden();

    expect($this->superAdmin->refresh()->status)->toBe(UserStatus::Active)
        ->and($user->refresh()->role)->toBe(UserRole::User);
});

test('administrators do not manage other administrators, directly or through their group', function () {
    $peer = User::factory()->admin()->create();
    $group = Group::factory()->create();

    $this->actingAs($this->admin)->put(route('admin.users.status', $peer), ['status' => 'disabled'])->assertForbidden();
    $this->actingAs($this->admin)->put(route('admin.users.budget', $peer), ['monthly_limit_usd' => '1'])->assertForbidden();
    $this->actingAs($this->admin)->put(route('admin.users.group', $peer), ['group_id' => $group->id])->assertForbidden();

    $peer->forceFill(['group_id' => $group->id])->save();
    $this->actingAs($this->admin)->put(route('admin.groups.update', $group), ['name' => $group->name, 'budget_policy_id' => $group->budget_policy_id, 'requests_per_minute' => 1, 'max_concurrent_streams' => 1, 'alias_ids' => []])->assertForbidden();

    // A super administrator does.
    $this->actingAs($this->superAdmin)->put(route('admin.users.status', $peer), ['status' => 'disabled'])->assertSessionHasNoErrors();

    expect($peer->refresh()->status)->toBe(UserStatus::Disabled);
});

test('a super administrator changes roles', function () {
    $user = member();

    $this->actingAs($this->superAdmin)
        ->put(route('admin.users.role', $user), ['role' => 'admin'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->role)->toBe(UserRole::Admin)
        ->and(AuditLog::query()->where('action', 'user.role_changed')->sole()->new_values)->toBe(['role' => 'admin']);
});

test('the last active super administrator cannot be demoted or disabled', function () {
    $second = User::factory()->superAdmin()->create();
    // A disabled super administrator does not count.
    User::factory()->superAdmin()->create(['status' => UserStatus::Disabled]);

    // While another active super administrator exists, demotion works.
    $this->actingAs($this->superAdmin)->put(route('admin.users.role', $second), ['role' => 'admin'])->assertSessionHasNoErrors();

    // Now $this->superAdmin is the last one; the service refuses (the HTTP
    // routes already refuse because nobody can act on themselves).
    $users = app(UserAdministration::class);
    expect(fn () => $users->changeRole($this->superAdmin, UserRole::User))->toThrow(LastSuperAdmin::class)
        ->and(fn () => $users->setStatus($this->superAdmin, UserStatus::Disabled))->toThrow(LastSuperAdmin::class)
        ->and($this->superAdmin->refresh()->role)->toBe(UserRole::SuperAdmin);
});

test('a super administrator records adjustments; credits cannot exceed spending', function () {
    $user = member('10');
    app(BudgetPeriods::class)->current($user);
    BudgetPeriod::query()->where('user_id', $user->id)->update(['spent_usd' => '2']);

    $this->actingAs($this->superAdmin)
        ->post(route('admin.users.adjustments', $user), ['amount_usd' => '-1.50', 'reason' => 'Failed request refund'])
        ->assertSessionHasNoErrors();

    expect(BudgetPeriod::query()->where('user_id', $user->id)->sole()->spent_usd->toString())->toBe('0.5000000000')
        ->and(UsageEvent::query()->sole()->created_by)->toBe($this->superAdmin->id);

    $this->actingAs($this->superAdmin)
        ->post(route('admin.users.adjustments', $user), ['amount_usd' => '-5', 'reason' => 'Too much'])
        ->assertSessionHasErrors('amount_usd');

    $this->actingAs($this->superAdmin)->get(route('admin.users.show', $user))
        ->assertInertia(fn ($page) => $page
            ->where('adjustments.0.amount_usd', '-1.50')
            ->where('adjustments.0.reason', 'Failed request refund')
            ->where('adjustments.0.by', 'Root'));
});

test('adjustments need an amount and a reason', function (array $payload, string $error) {
    $this->actingAs($this->superAdmin)
        ->post(route('admin.users.adjustments', member()), $payload)
        ->assertSessionHasErrors($error);
})->with([
    'zero' => [['amount_usd' => '0', 'reason' => 'x'], 'amount_usd'],
    'no reason' => [['amount_usd' => '1', 'reason' => ''], 'reason'],
    'too precise' => [['amount_usd' => '0.001', 'reason' => 'x'], 'amount_usd'],
]);

test('last activity is recorded at most every few minutes', function () {
    $user = member();

    $this->actingAs($user)->get(route('home'));
    $first = $user->refresh()->last_active_at;
    expect($first?->toIso8601String())->toBe('2026-10-15T12:00:00+00:00');

    $this->travel(2)->minutes();
    $this->actingAs($user->refresh())->get(route('home'));
    expect($user->refresh()->last_active_at?->equalTo($first))->toBeTrue();

    $this->travel(4)->minutes();
    $this->actingAs($user->refresh())->get(route('home'));
    expect($user->refresh()->last_active_at?->toIso8601String())->toBe('2026-10-15T12:06:00+00:00');
});

test('the audit log lists entries and filters them', function () {
    $user = member();
    $this->actingAs($this->admin)->put(route('admin.users.budget', $user), ['monthly_limit_usd' => '20']);
    $this->actingAs($this->admin)->put(route('admin.users.status', $user), ['status' => 'disabled']);

    $this->actingAs($this->admin)->get(route('admin.audit-log.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/audit-log/index')
            ->where('entries.data.0.action', 'user.status_changed')
            ->where('entries.data.0.actor.name', 'Helper')
            ->where('entries.data.0.subject_label', $user->email)
            ->where('entries.data.0.new_values', ['status' => 'disabled']));

    $this->actingAs($this->admin)->get(route('admin.audit-log.index', ['action' => 'user.budget_override_changed']))
        ->assertInertia(fn ($page) => $page->where('entries.total', 1));

    $this->actingAs($this->admin)->get(route('admin.audit-log.index', ['actor' => 'nobody']))
        ->assertInertia(fn ($page) => $page->where('entries.total', 0));

    $this->actingAs($this->admin)->get(route('admin.audit-log.index', ['from' => '2026-10-16']))
        ->assertInertia(fn ($page) => $page->where('entries.total', 0));
});

test('the users list carries quick-action permissions per row', function () {
    $admin = User::factory()->admin()->create();
    $super = User::factory()->superAdmin()->create();
    $member = User::factory()->create();

    $rows = collect($this->actingAs($admin)->get(route('admin.users.index'))->inertiaProps('users.data'))->keyBy('id');

    expect($rows[$member->id]['can'])->toBe(['changeStatus' => true, 'changeRole' => false])
        ->and($rows[$super->id]['can'])->toBe(['changeStatus' => false, 'changeRole' => false])
        ->and($rows[$admin->id]['can'])->toBe(['changeStatus' => false, 'changeRole' => false]);

    $rows = collect($this->actingAs($super)->get(route('admin.users.index'))->inertiaProps('users.data'))->keyBy('id');

    expect($rows[$member->id]['can'])->toBe(['changeStatus' => true, 'changeRole' => true]);
});

test('quick actions from the list return to the list', function () {
    $super = User::factory()->superAdmin()->create();
    $member = User::factory()->create();

    $this->actingAs($super)
        ->from(route('admin.users.index'))
        ->put(route('admin.users.status', $member), ['status' => 'disabled', 'stay' => true])
        ->assertRedirect(route('admin.users.index'));

    $this->from(route('admin.users.index'))
        ->put(route('admin.users.role', $member), ['role' => 'admin', 'stay' => true])
        ->assertRedirect(route('admin.users.index'));

    expect($member->refresh()->status->value)->toBe('disabled')
        ->and($member->role->value)->toBe('admin');

    // Without "stay" the user page follows, as before.
    $this->put(route('admin.users.status', $member), ['status' => 'active'])
        ->assertRedirect(route('admin.users.show', $member));
});
