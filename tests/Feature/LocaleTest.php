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
        ->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('locale.current', 'tr'));
});

test('unsupported locales fall back', function () {
    updateSettings(InstitutionSettings::class, ['default_locale' => 'de']);
    config(['ada.locales.fallback' => 'en']);

    $this->withHeader('Accept-Language', 'de-DE')
        ->get(route('login'))
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
