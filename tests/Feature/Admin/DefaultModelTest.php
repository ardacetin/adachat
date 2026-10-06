<?php

use App\Domain\Institution\Settings\InstitutionSettings;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\ModelAlias;
use App\Models\User;

beforeEach(function () {
    $this->first = ModelAlias::factory()->create(['sort_order' => 1]);
    $this->second = ModelAlias::factory()->create(['sort_order' => 2]);
    $this->first->groups()->attach(Group::default());
    $this->second->groups()->attach(Group::default());
});

test('only super admins choose the default model', function (string $role, int $status) {
    $user = match ($role) {
        'user' => User::factory()->create(),
        'admin' => User::factory()->admin()->create(),
        'super_admin' => User::factory()->superAdmin()->create(),
    };

    $this->actingAs($user)
        ->put(route('admin.aliases.default'), ['default_model_alias_id' => $this->second->id])
        ->assertStatus($status);
})->with([
    'user' => ['user', 403],
    'admin' => ['admin', 403],
    'super admin' => ['super_admin', 302],
]);

test('the default is saved, shown and audited', function () {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)
        ->put(route('admin.aliases.default'), ['default_model_alias_id' => $this->second->id])
        ->assertRedirect(route('admin.aliases.index'));

    expect(app(InstitutionSettings::class)->default_model_alias_id)->toBe($this->second->id);

    $log = AuditLog::query()->where('action', 'institution.default_model_changed')->sole();
    expect($log->old_values)->toBe(['default_model_alias_id' => null])
        ->and($log->new_values)->toBe(['default_model_alias_id' => $this->second->id]);

    $this->actingAs($admin)->get(route('admin.aliases.index'))
        ->assertInertia(fn ($page) => $page->where('defaultAliasId', $this->second->id));

    // Clearing it brings back the old behaviour.
    $this->actingAs($admin)->put(route('admin.aliases.default'), ['default_model_alias_id' => null]);
    expect(app(InstitutionSettings::class)->default_model_alias_id)->toBeNull();
});

test('only an existing, enabled alias can be the default', function () {
    $disabled = ModelAlias::factory()->create(['enabled' => false]);
    $admin = User::factory()->superAdmin()->create();

    foreach ([$disabled->id, 999999, 'x'] as $value) {
        $this->actingAs($admin)
            ->put(route('admin.aliases.default'), ['default_model_alias_id' => $value])
            ->assertSessionHasErrors('default_model_alias_id');
    }

    expect(app(InstitutionSettings::class)->default_model_alias_id)->toBeNull();
});

test('the chat page marks the default for users who may use it', function () {
    updateSettings(InstitutionSettings::class, ['default_model_alias_id' => $this->second->id]);

    $this->actingAs(User::factory()->create())->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->where('aliases.0.id', $this->first->id)
            ->where('aliases.0.is_default', false)
            ->where('aliases.1.id', $this->second->id)
            ->where('aliases.1.is_default', true));

    // Outside the alias's groups: not offered, so nothing is marked.
    $this->second->groups()->detach();

    $this->actingAs(User::factory()->create())->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->has('aliases', 1)
            ->where('aliases.0.is_default', false));
});
