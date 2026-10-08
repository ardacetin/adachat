<?php

use App\Models\User;

test('users can save their appearance', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('appearance.edit'))
        ->put(route('appearance.update'), ['appearance' => 'dark'])
        ->assertRedirect(route('appearance.edit'));

    expect($user->fresh()->appearance->value)->toBe('dark');
});

test('only known appearances are accepted', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('appearance.update'), ['appearance' => 'sepia'])
        ->assertSessionHasErrors('appearance');
});

test('the saved preference renders the first paint and wins over the cookie', function () {
    $user = User::factory()->create(['appearance' => 'dark']);

    $this->actingAs($user)
        ->withUnencryptedCookie('appearance', 'light')
        ->get(route('home'))
        ->assertSee('class="dark"', false)
        ->assertSee("localStorage.setItem('appearance', appearance)", false);
});

test('guest cookies are validated', function () {
    $this->withUnencryptedCookie('appearance', "dark';alert(1);//")
        ->get(route('home'))
        ->assertDontSee('alert(1)', false)
        ->assertSee("const appearance = 'system';", false);
});
