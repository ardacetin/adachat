<?php

use App\Models\User;

test('guests see the landing page and are sent to sign in from everywhere else', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome')->has('providers')->where('auth.user', null));

    $this->get(route('usage'))->assertRedirect(route('login'));
});

test('the login page is rendered for guests', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/login'));
});

test('authenticated users land on the chat', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('chat/index'));
});

test('users can log out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('disabled users are logged out on their next request', function () {
    $user = User::factory()->create();

    $this->actingAs($user);
    $user->forceFill(['status' => 'disabled', 'disabled_at' => now()])->save();

    $this->get(route('home'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('auth');

    $this->assertGuest();
});

test('shared props expose only the public user fields', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.name', 'Ada Lovelace')
            ->where('auth.user.role', 'user')
            ->missing('auth.user.remember_token')
            ->missing('auth.user.created_at'));
});
