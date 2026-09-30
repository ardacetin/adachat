<?php

use App\Domain\Institution\Settings\InstitutionSettings;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function institutionPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Example University',
        'short_name' => 'EXU',
        'domain' => 'example.edu',
        'support_email' => 'it@example.edu',
        'default_locale' => 'tr',
        'timezone' => 'Europe/Istanbul',
        'privacy_url' => 'https://example.edu/privacy',
        'terms_url' => '',
        'primary_color' => '#1E40AF',
        'budget_display' => 'percent',
    ], $overrides);
}

beforeEach(function () {
    Storage::fake('public');
});

test('only super admins can manage institution settings', function (string $role, int $status) {
    $user = match ($role) {
        'user' => User::factory()->create(),
        'admin' => User::factory()->admin()->create(),
        'super_admin' => User::factory()->superAdmin()->create(),
    };

    $this->actingAs($user)->get(route('admin.institution.edit'))->assertStatus($status);
    $this->actingAs($user)->post(route('admin.institution.update'), institutionPayload())
        ->assertStatus($status === 200 ? 302 : $status);
})->with([
    'user' => ['user', 403],
    'admin' => ['admin', 403],
    'super admin' => ['super_admin', 200],
]);

test('settings are saved, normalised and audited', function () {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)
        ->post(route('admin.institution.update'), institutionPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.institution.edit'));

    $settings = app(InstitutionSettings::class);
    expect($settings->short_name)->toBe('EXU')
        ->and($settings->primary_color)->toBe('#1e40af')
        ->and($settings->timezone)->toBe('Europe/Istanbul')
        ->and($settings->budget_display)->toBe('percent')
        ->and($settings->terms_url)->toBeNull();

    $log = AuditLog::query()->sole();
    expect($log->action)->toBe('institution.settings_updated')
        ->and($log->actor_id)->toBe($admin->id)
        ->and($log->new_values)->toHaveKey('primary_color', '#1e40af')
        ->and($log->new_values)->not->toHaveKey('name');
});

test('invalid values are rejected', function (array $overrides, string $field) {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.institution.update'), institutionPayload($overrides))
        ->assertSessionHasErrors($field);
})->with([
    'too light colour' => [['primary_color' => '#ffff00'], 'primary_color'],
    'unknown budget display' => [['budget_display' => 'hidden'], 'budget_display'],
    'not a colour' => [['primary_color' => 'blue'], 'primary_color'],
    'unknown locale' => [['default_locale' => 'de'], 'default_locale'],
    'unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'bad domain' => [['domain' => 'not a domain'], 'domain'],
    'javascript url' => [['privacy_url' => 'javascript:alert(1)'], 'privacy_url'],
    'missing name' => [['name' => ''], 'name'],
]);

test('logos are uploaded, replaced and removed', function () {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->post(route('admin.institution.update'), institutionPayload([
        'logo' => UploadedFile::fake()->image('logo.png', 200, 60),
        'favicon' => UploadedFile::fake()->image('favicon.png', 64, 64),
    ]))->assertSessionHasNoErrors();

    $first = app(InstitutionSettings::class)->logo_path;
    expect($first)->toStartWith('branding/logo-')->toEndWith('.png');
    Storage::disk('public')->assertExists($first);

    $this->actingAs($admin)->post(route('admin.institution.update'), institutionPayload([
        'logo' => UploadedFile::fake()->image('logo2.webp', 200, 60),
    ]))->assertSessionHasNoErrors();

    $second = app(InstitutionSettings::class)->logo_path;
    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);

    $this->actingAs($admin)->post(route('admin.institution.update'), institutionPayload([
        'remove_logo' => true,
    ]))->assertSessionHasNoErrors();

    expect(app(InstitutionSettings::class)->logo_path)->toBeNull();
    Storage::disk('public')->assertMissing($second);
});

test('unsafe uploads are rejected', function (string $field, UploadedFile $file) {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.institution.update'), institutionPayload([$field => $file]))
        ->assertSessionHasErrors($field);

    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'svg logo' => ['logo', fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
    'too large logo' => ['logo', fn () => UploadedFile::fake()->image('logo.png')->size(2048)],
    'non-square favicon' => ['favicon', fn () => UploadedFile::fake()->image('favicon.png', 64, 32)],
]);

test('branding reaches every page', function () {
    updateSettings(InstitutionSettings::class, ['primary_color' => '#1e40af', 'short_name' => 'EXU']);

    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertSee(':root{--primary:oklch(0.4244 0.1809 265.64);', false)
        ->assertInertia(fn ($page) => $page
            ->where('institution.shortName', 'EXU')
            ->where('institution.logoUrl', null));
});

test('the neutral theme is used without a colour', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertDontSee(':root{--primary', false);
});
