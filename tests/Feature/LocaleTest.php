<?php

use App\Domain\Institution\Settings\InstitutionSettings;
use App\Models\User;

test('the user preference wins', function () {
    updateSettings(InstitutionSettings::class, ['default_locale' => 'en']);

    $this->actingAs(User::factory()->create(['locale' => 'tr']))
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('locale.current', 'tr'));
});

test('the institution default applies when the user has no preference', function () {
    updateSettings(InstitutionSettings::class, ['default_locale' => 'tr']);

    $this->actingAs(User::factory()->create(['locale' => null]))
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('locale.current', 'tr'));
});

test('the browser language is used when the institution default is unavailable', function () {
    updateSettings(InstitutionSettings::class, ['default_locale' => 'de']);

    $this->withHeader('Accept-Language', 'tr-TR,tr;q=0.9,en;q=0.5')
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('locale.current', 'tr'));
});

test('unsupported locales fall back', function () {
    updateSettings(InstitutionSettings::class, ['default_locale' => 'de']);
    config(['ada.locales.fallback' => 'en']);

    $this->withHeader('Accept-Language', 'de-DE')
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->where('locale.current', 'en')
            ->where('locale.available', ['en', 'tr']));
});

test('server-side messages are translated', function () {
    $user = User::factory()->create(['locale' => 'tr']);

    $this->actingAs($user)
        ->put(route('language.update'), ['locale' => 'xx'])
        ->assertSessionHasErrors(['locale' => 'Seçilen dil geçersiz.']);
});

test('a visitor chooses the language of the landing and sign-in pages', function () {
    updateSettings(InstitutionSettings::class, ['default_locale' => 'en']);

    $response = $this->from(route('home'))->post(route('locale.update'), ['locale' => 'tr']);
    $response->assertRedirect(route('home'))->assertCookie('ada_locale', 'tr');

    // The choice wins over the institution default and the browser.
    $this->withCookie('ada_locale', 'tr')->withHeader('Accept-Language', 'en')
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page->component('welcome')->where('locale.current', 'tr'));
    $this->withCookie('ada_locale', 'tr')
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('locale.current', 'tr'));

    // A cookie with a language Ada does not offer is ignored.
    $this->withCookie('ada_locale', 'xx')
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('locale.current', 'en'));

    $this->post(route('locale.update'), ['locale' => 'xx'])->assertSessionHasErrors('locale');
});

test('a signed-in user\'s choice is saved as their preference, which wins over the cookie', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)->post(route('locale.update'), ['locale' => 'tr'])->assertRedirect();
    expect($user->refresh()->locale)->toBe('tr');

    $this->actingAs($user)->withCookie('ada_locale', 'en')
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('locale.current', 'tr'));
});
