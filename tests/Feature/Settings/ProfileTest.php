<?php

use App\Models\User;

test('the profile page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/profile'));
});
