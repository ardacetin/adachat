<?php

use App\Models\User;

test('sessions end after the absolute maximum however active they are', function () {
    config(['ada.auth.dev_login' => true, 'ada.auth.max_session_minutes' => 60]);
    $user = User::factory()->create();

    $this->post('/dev/login', ['user_id' => $user->id])->assertRedirect(route('home'));
    $this->get(route('home'))->assertOk();

    $this->travel(59)->minutes();
    $this->get(route('home'))->assertOk();

    $this->travel(2)->minutes();
    $this->get(route('home'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['auth' => __('auth.errors.session_expired')]);
    $this->assertGuest();
});

test('sessions from before the check start counting at their next request', function () {
    config(['ada.auth.max_session_minutes' => 60]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'))->assertOk();
    $this->travel(30)->minutes();
    $this->actingAs($user)->get(route('home'))->assertOk();
    $this->travel(31)->minutes();
    $this->actingAs($user)->get(route('home'))->assertRedirect(route('login'));
});

test('an expired session gets a JSON answer from the chat', function () {
    config(['ada.auth.max_session_minutes' => 1]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'));
    $this->travel(2)->minutes();

    $this->actingAs($user)->postJson(route('messages.store'), ['content' => 'Hi'])
        ->assertUnauthorized()
        ->assertJson(['code' => 'session_expired']);
});
