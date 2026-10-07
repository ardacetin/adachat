<?php

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Domain\Institution\Settings\AuthSettings;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\User;
use App\Models\UserIdentity;
use Tests\Support\FakeIdentityProvider;

beforeEach(function () {
    updateSettings(AuthSettings::class, [
        'allowed_domains' => ['example.edu'],
        'auto_provision' => true,
        'group_mapping' => true,
        'group_mapping_unmatched' => 'keep',
    ]);

    $this->idp = new FakeIdentityProvider;
    $this->app->instance(IdentityProviderRegistry::class, new IdentityProviderRegistry([$this->idp]));

    $this->staff = Group::factory()->create(['name' => 'Staff', 'idp_groups' => ['ada-staff', 'Teachers'], 'idp_priority' => 10]);
    $this->students = Group::factory()->create(['name' => 'Students', 'idp_groups' => ['ada-students'], 'idp_priority' => 50]);
});

/**
 * @param  list<string>|null  $groups
 */
function signInWithGroups(?array $groups): void
{
    test()->idp->next = FakeIdentityProvider::identity(['groups' => $groups]);
    test()->post(route('auth.acs', 'saml'));
    auth()->logout();
}

test('a new user is placed in the group their identity provider groups map to', function () {
    signInWithGroups(['ada-students', 'unrelated']);

    $user = User::query()->sole();
    expect($user->group_id)->toBe($this->students->id)
        ->and($user->group_pinned)->toBeFalse()
        ->and(UserIdentity::query()->sole()->last_claims['groups'])->toBe('ada-students, unrelated');
});

test('values compare case-insensitively and the lowest priority wins', function () {
    signInWithGroups(['ADA-STUDENTS', 'teachers']);

    expect(User::query()->sole()->group_id)->toBe($this->staff->id);
});

test('later sign-ins follow the identity provider and are audited', function () {
    signInWithGroups(['ada-students']);
    $user = User::query()->sole();

    signInWithGroups(['ada-staff']);

    expect($user->refresh()->group_id)->toBe($this->staff->id);
    $log = AuditLog::query()->where('action', 'user.group_changed')->sole();
    expect($log->old_values)->toBe(['group_id' => $this->students->id])
        ->and($log->new_values)->toEqual(['group_id' => $this->staff->id, 'source' => 'identity_provider'])
        ->and($log->actor_id)->toBeNull();
});

test('a group pinned by an administrator is kept', function () {
    signInWithGroups(['ada-students']);
    $user = User::query()->sole();
    $user->forceFill(['group_id' => Group::default()->id, 'group_pinned' => true])->save();

    signInWithGroups(['ada-staff']);

    expect($user->refresh()->group_id)->toBe(Group::default()->id);
});

test('unmatched users keep their group, go to the default group or are refused', function () {
    signInWithGroups(['ada-staff']);
    $user = User::query()->sole();

    // keep
    signInWithGroups(['unrelated']);
    expect($user->refresh()->group_id)->toBe($this->staff->id);

    // default
    updateSettings(AuthSettings::class, ['group_mapping_unmatched' => 'default']);
    signInWithGroups([]);
    expect($user->refresh()->group_id)->toBe(Group::default()->id);

    // reject
    updateSettings(AuthSettings::class, ['group_mapping_unmatched' => 'reject']);
    $this->idp->next = FakeIdentityProvider::identity(['groups' => ['unrelated']]);
    $this->post(route('auth.acs', 'saml'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['auth' => __('auth.errors.no_mapped_group')]);
    $this->assertGuest();
});

test('a refused first sign-in creates no account', function () {
    updateSettings(AuthSettings::class, ['group_mapping_unmatched' => 'reject']);

    signInWithGroups(['unrelated']);

    expect(User::query()->count())->toBe(0);
});

test('administrators and pinned users are never refused for their groups', function () {
    updateSettings(AuthSettings::class, ['group_mapping_unmatched' => 'reject']);
    $admin = User::factory()->create(['email' => 'ada@example.edu', 'role' => UserRole::Admin]);

    $this->idp->next = FakeIdentityProvider::identity(['groups' => []]);
    $this->post(route('auth.acs', 'saml'))->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($admin);
});

test('nothing changes while mapping is off or the provider sends no groups', function () {
    signInWithGroups(['ada-staff']);
    $user = User::query()->sole();

    updateSettings(AuthSettings::class, ['group_mapping_unmatched' => 'default']);
    signInWithGroups(null);
    expect($user->refresh()->group_id)->toBe($this->staff->id);

    updateSettings(AuthSettings::class, ['group_mapping' => false]);
    signInWithGroups(['ada-students']);
    expect($user->refresh()->group_id)->toBe($this->staff->id);
});
