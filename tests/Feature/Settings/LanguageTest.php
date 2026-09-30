<?php

use App\Models\User;

test('the language page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('language.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/language'));
});

test('users can change their language', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->put(route('language.update'), ['locale' => 'tr'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('language.edit'));

    expect($user->fresh()->locale)->toBe('tr');
});

test('only available languages are accepted', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->put(route('language.update'), ['locale' => 'de'])
        ->assertSessionHasErrors('locale');

    expect($user->fresh()->locale)->toBe('en');
});
