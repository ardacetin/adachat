<?php

use App\Domain\Institution\Settings\AuthSettings;

test('the Google redirect requests OpenID scopes with a state and domain hint', function () {
    updateSettings(AuthSettings::class, ['allowed_domains' => ['example.edu']]);
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-secret',
        'services.google.redirect' => 'http://localhost/auth/google/callback',
    ]);

    $response = $this->get(route('auth.redirect', 'google'));

    $location = $response->headers->get('Location');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/auth')
        ->and($query['client_id'])->toBe('test-client-id')
        ->and($query['hd'])->toBe('example.edu')
        ->and($query['prompt'])->toBe('select_account')
        ->and(explode(' ', $query['scope']))->toContain('openid', 'email', 'profile')
        ->and($query['state'])->toBe(session('state'));
});

test('Google is not offered when it is not configured', function () {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

    $this->get(route('auth.redirect', 'google'))->assertNotFound();
    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('providers', []));
});

test('a callback with a mismatched state is rejected', function () {
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-secret',
    ]);

    $this->withSession(['state' => 'expected-state'])
        ->get(route('auth.callback', ['provider' => 'google', 'state' => 'forged-state', 'code' => 'abc']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['auth' => __('auth.errors.invalid_state')]);

    $this->assertGuest();
});

test('a callback carrying a provider error is rejected', function () {
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-secret',
    ]);

    $this->get(route('auth.callback', ['provider' => 'google', 'error' => 'access_denied']))
        ->assertSessionHasErrors(['auth' => __('auth.errors.provider_error')]);

    $this->assertGuest();
});
