<?php

use App\Models\Group;
use App\Models\User;

test('it creates a super admin in the default group', function () {
    $this->artisan('ada:user:promote', ['email' => 'Root@Example.edu'])->assertSuccessful();

    $user = User::query()->sole();

    expect($user->email)->toBe('root@example.edu')
        ->and($user->name)->toBe('root')
        ->and($user->role->value)->toBe('super_admin')
        ->and($user->group_id)->toBe(Group::default()->id);
});

test('it changes the role of an existing user', function () {
    $user = User::factory()->create(['email' => 'staff@example.edu']);

    $this->artisan('ada:user:promote', ['email' => 'staff@example.edu', '--role' => 'admin'])->assertSuccessful();

    expect($user->fresh()->role->value)->toBe('admin')
        ->and(User::query()->count())->toBe(1);
});

test('it rejects invalid input', function (array $arguments) {
    $this->artisan('ada:user:promote', $arguments)->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'invalid e-mail' => [['email' => 'not-an-email']],
    'unknown role' => [['email' => 'a@example.edu', '--role' => 'owner']],
]);

test('install warns when no domain is allowed', function () {
    config(['ada.auth.allowed_domains' => []]);

    $this->artisan('ada:install')
        ->expectsOutputToContain('AUTH_ALLOWED_DOMAINS is empty')
        ->assertSuccessful();

    expect(Group::query()->where('is_default', true)->count())->toBe(1);
});

test('install warns when no identity provider is configured', function () {
    config(['ada.auth.allowed_domains' => ['example.edu'], 'services.google.client_id' => null]);

    $this->artisan('ada:install')
        ->expectsOutputToContain('No identity provider is configured')
        ->assertSuccessful();
});
