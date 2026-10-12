<?php

use App\Models\User;

test('the profile page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/profile'));
});

test('/settings opens the profile page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings')
        ->assertRedirect('/settings/profile');
});
