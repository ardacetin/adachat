<?php

use App\Models\User;

test('the dev login lists users on the login page', function () {
    User::factory()->create(['name' => 'Dev User']);

    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page
            ->component('auth/login')
            ->where('devLoginUsers.0.name', 'Dev User'));
});

test('users can sign in with the dev login', function () {
    $user = User::factory()->create();

    $this->post(route('dev-login'), ['user_id' => $user->id])
        ->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

test('disabled users cannot sign in with the dev login', function () {
    $user = User::factory()->disabled()->create();

    $this->post(route('dev-login'), ['user_id' => $user->id])
        ->assertSessionHasErrors('auth');

    $this->assertGuest();
});

test('the dev login is refused when the flag is off', function () {
    config(['ada.auth.dev_login' => false]);
    $user = User::factory()->create();

    $this->post(route('dev-login'), ['user_id' => $user->id])->assertNotFound();

    $this->assertGuest();
});
