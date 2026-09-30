<?php

use App\Models\User;

test('role abilities', function (string $state, bool $accessAdmin, bool $manageSystem) {
    $user = $state === 'user' ? User::factory()->create() : User::factory()->{$state}()->create();

    expect($user->can('access-admin'))->toBe($accessAdmin)
        ->and($user->can('manage-system'))->toBe($manageSystem);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->where('can.accessAdmin', $accessAdmin)
            ->where('can.manageSystem', $manageSystem));
})->with([
    'user' => ['user', false, false],
    'admin' => ['admin', true, false],
    'super admin' => ['superAdmin', true, true],
]);
