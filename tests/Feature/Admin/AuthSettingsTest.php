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

    $this->post(route('auth.acs', 'saml'))->assertSessionHasErrors('auth');
    $this->assertGuest();
});

test('group mapping is turned on with a rule for unmatched users', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.authentication.update'), [
            'allowed_domains' => 'example.edu',
            'auto_provision' => true,
            'group_mapping' => true,
            'group_mapping_unmatched' => 'reject',
        ])
        ->assertSessionHasNoErrors();

    $settings = app(AuthSettings::class);
    expect($settings->group_mapping)->toBeTrue()
        ->and($settings->group_mapping_unmatched)->toBe('reject');

    $this->put(route('admin.authentication.update'), [
        'allowed_domains' => 'example.edu',
        'auto_provision' => true,
        'group_mapping' => true,
        'group_mapping_unmatched' => 'everyone',
    ])->assertSessionHasErrors('group_mapping_unmatched');

    $this->get(route('admin.authentication.edit'))->assertInertia(fn ($page) => $page
        ->where('settings.group_mapping', true)
        ->where('mapped_groups', 0)
        ->where('saml.groups_attribute', null)
        ->where('oidc.groups_claim', null));
});
