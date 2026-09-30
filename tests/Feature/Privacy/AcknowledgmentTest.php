<?php

use App\Domain\Institution\Settings\PrivacySettings;
use App\Models\AuditLog;
use App\Models\User;

test('a first sign-in shows the usage notice before anything else', function () {
    $user = User::factory()->unacknowledged()->create();

    $this->actingAs($user)->get(route('home'))->assertRedirect(route('acknowledgment.show'));
    $this->actingAs($user)->get(route('usage'))->assertRedirect(route('acknowledgment.show'));
    $this->actingAs($user)->get(route('acknowledgment.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/acknowledgment')->where('text', null));

    // Chat requests are refused, not redirected.
    $this->actingAs($user)->postJson(route('messages.store'), ['content' => 'Hi'])
        ->assertForbidden()
        ->assertJson(['code' => 'acknowledgment_required']);

    // Signing out and changing the language still work.
    $this->actingAs($user)->get(route('language.edit'))->assertOk();
});

test('acknowledging records the version and continues', function () {
    $user = User::factory()->unacknowledged()->create();

    $this->actingAs($user)->post(route('acknowledgment.store'))->assertRedirect(route('home'));

    expect($user->refresh()->acknowledged_version)->toBe(1)
        ->and($user->acknowledged_at)->not->toBeNull();

    $this->actingAs($user)->get(route('home'))->assertOk();
    $this->actingAs($user)->get(route('acknowledgment.show'))->assertRedirect(route('home'));
});

test('a new version asks everyone again, and a disabled notice asks nobody', function () {
    $user = User::factory()->create();
    updateSettings(PrivacySettings::class, ['acknowledgment_version' => 2]);

    $this->actingAs($user)->get(route('home'))->assertRedirect(route('acknowledgment.show'));

    updateSettings(PrivacySettings::class, ['acknowledgment_enabled' => false]);

    $this->actingAs($user)->get(route('home'))->assertOk();
});

test('a custom notice is shown in the user\'s language', function () {
    updateSettings(PrivacySettings::class, ['acknowledgment_text' => ['en' => "Hello.\n\nSecond paragraph.", 'tr' => 'Merhaba.']]);
    $user = User::factory()->unacknowledged()->create(['locale' => 'tr']);

    $this->actingAs($user)->get(route('acknowledgment.show'))
        ->assertInertia(fn ($page) => $page->where('text', 'Merhaba.'));
});

test('super administrators edit the notice and retention', function () {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.privacy.edit'))->assertForbidden();

    $this->actingAs($admin)->get(route('admin.privacy.edit'))
        ->assertInertia(fn ($page) => $page->component('admin/privacy')->where('settings.usage_retention_months', 24));

    $this->actingAs($admin)->put(route('admin.privacy.update'), [
        'acknowledgment_enabled' => true,
        'acknowledgment_text' => ['en' => '  Our notice.  ', 'tr' => ''],
        'conversation_retention_days' => '365',
        'deleted_conversation_days' => '7',
        'usage_retention_months' => '36',
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.privacy.edit'));

    $settings = app(PrivacySettings::class)->refresh();
    expect($settings->acknowledgment_text)->toBe(['en' => 'Our notice.'])
        // The text changed, so everyone acknowledges again.
        ->and($settings->acknowledgment_version)->toBe(2)
        ->and($settings->conversation_retention_days)->toBe(365)
        ->and($settings->deleted_conversation_days)->toBe(7)
        ->and($settings->usage_retention_months)->toBe(36)
        ->and(AuditLog::query()->where('action', 'privacy.settings_updated')->exists())->toBeTrue()
        // The author has read the new notice; everyone else is asked again.
        ->and($admin->refresh()->acknowledged_version)->toBe(2);

    // Saving the same text keeps the version; "ask again" raises it.
    $payload = ['acknowledgment_enabled' => true, 'acknowledgment_text' => ['en' => 'Our notice.'], 'conversation_retention_days' => '', 'deleted_conversation_days' => '7', 'usage_retention_months' => '36'];
    // In tests the settings object lives across requests; reload it as a new request would.
    app(PrivacySettings::class)->refresh();
    $this->actingAs($admin)->put(route('admin.privacy.update'), $payload)->assertSessionHasNoErrors();
    expect(app(PrivacySettings::class)->refresh()->acknowledgment_version)->toBe(2)
        ->and(app(PrivacySettings::class)->refresh()->conversation_retention_days)->toBeNull();

    $this->actingAs($admin)->put(route('admin.privacy.update'), [...$payload, 'ask_again' => true])->assertSessionHasNoErrors();
    expect(app(PrivacySettings::class)->refresh()->acknowledgment_version)->toBe(3);
});

test('retention values are validated', function (array $overrides, string $error) {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.privacy.update'), [
            'acknowledgment_enabled' => true,
            'deleted_conversation_days' => '30',
            'usage_retention_months' => '24',
            ...$overrides,
        ])
        ->assertSessionHasErrors($error);
})->with([
    'usage too short' => [['usage_retention_months' => '6'], 'usage_retention_months'],
    'negative days' => [['deleted_conversation_days' => '-1'], 'deleted_conversation_days'],
    'zero retention' => [['conversation_retention_days' => '0'], 'conversation_retention_days'],
]);
