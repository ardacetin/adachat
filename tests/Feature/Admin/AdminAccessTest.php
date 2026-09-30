<?php

use App\Models\User;

test('the admin area is for admins', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.index'))->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/index'));
});

test('guests are sent to the login page', function () {
    $this->get(route('admin.index'))->assertRedirect(route('login'));
});
