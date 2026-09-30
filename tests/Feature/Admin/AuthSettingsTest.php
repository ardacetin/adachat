<?php

use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Domain\Institution\Settings\AuthSettings;
use App\Models\AuditLog;
use App\Models\User;
use Tests\Support\FakeIdentityProvider;

test('only super admins can manage sign-in settings', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.authentication.edit'))
        ->assertForbidden();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.authentication.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/authentication'));
});

test('domains are accepted one per line and normalised', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.authentication.update'), [
            'allowed_domains' => " Example.EDU\nstaff.example.edu\n\nexample.edu ",
            'auto_provision' => false,
        ])
        ->assertSessionHasNoErrors();

    $settings = app(AuthSettings::class);
    expect($settings->allowed_domains)->toBe(['example.edu', 'staff.example.edu'])
        ->and($settings->auto_provision)->toBeFalse()
        ->and(AuditLog::query()->sole()->action)->toBe('auth.settings_updated');
});

test('removing every domain is refused', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.authentication.update'), ['allowed_domains' => "\n", 'auto_provision' => true])
        ->assertSessionHasErrors('allowed_domains');

    expect(app(AuthSettings::class)->allowed_domains)->toBe(['example.edu']);
});

test('invalid domains are refused', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.authentication.update'), ['allowed_domains' => "example.edu\n@evil", 'auto_provision' => true])
        ->assertSessionHasErrors('allowed_domains.1');
});

test('sign-in follows the saved domains', function () {
    $idp = new FakeIdentityProvider;
    $this->app->instance(IdentityProviderRegistry::class, new IdentityProviderRegistry([$idp]));

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.authentication.update'), ['allowed_domains' => 'other.edu', 'auto_provision' => true]);
    auth()->logout();

    $this->get(route('auth.callback', 'google'))->assertSessionHasErrors('auth');
    $this->assertGuest();
});
