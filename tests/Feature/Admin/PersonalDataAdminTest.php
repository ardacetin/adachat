<?php

use App\Domain\PersonalData\PersonalDataSettings;
use App\Models\AuditLog;
use App\Models\User;

/**
 * @return array<string, mixed>
 */
function personalDataPayload(array $overrides = []): array
{
    return [
        'rules' => ['tckn' => 'mask', 'iban' => 'warn', 'card' => 'block', 'phone' => 'off', 'email' => 'off'],
        'patterns' => [['name' => 'Öğrenci no', 'pattern' => '\b20\d{7}\b', 'action' => 'mask']],
        ...$overrides,
    ];
}

test('only super administrators manage personal data rules', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.personal-data.edit'))->assertForbidden();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.personal-data.edit'))
        ->assertInertia(fn ($page) => $page->component('admin/personal-data')
            ->where('rules', ['tckn' => 'off', 'iban' => 'off', 'card' => 'off', 'phone' => 'off', 'email' => 'off'])
            ->where('patterns', []));
});

test('rules and patterns are saved and audited', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.personal-data.update'), personalDataPayload())
        ->assertSessionHasNoErrors();

    $settings = app(PersonalDataSettings::class);
    expect($settings->rules['tckn'])->toBe('mask')
        ->and($settings->patterns)->toBe([['name' => 'Öğrenci no', 'pattern' => '\b20\d{7}\b', 'action' => 'mask']])
        ->and(AuditLog::query()->sole()->action)->toBe('personal_data.settings_updated');
});

test('invalid rules and patterns are refused', function (array $overrides, string $error) {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(route('admin.personal-data.update'), personalDataPayload($overrides))
        ->assertSessionHasErrors($error);
})->with([
    'unknown action' => [['rules' => ['tckn' => 'hide', 'iban' => 'off', 'card' => 'off', 'phone' => 'off', 'email' => 'off']], 'rules.tckn'],
    'broken pattern' => [['patterns' => [['name' => 'X', 'pattern' => '(unclosed', 'action' => 'mask']]], 'patterns.0.pattern'],
    'matches everything' => [['patterns' => [['name' => 'X', 'pattern' => '\d*', 'action' => 'mask']]], 'patterns.0.pattern'],
    'built-in name' => [['patterns' => [['name' => 'tckn', 'pattern' => '\d+', 'action' => 'mask']]], 'patterns.0.name'],
    'same name twice' => [['patterns' => [['name' => 'No', 'pattern' => '\d+', 'action' => 'mask'], ['name' => 'no', 'pattern' => 'x', 'action' => 'mask']]], 'patterns.1.name'],
]);

test('a sample text can be checked with unsaved rules', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->postJson(route('admin.personal-data.test'), [...personalDataPayload(), 'sample' => 'TC 10000000146, öğrenci 201912345'])
        ->assertOk()
        ->assertJson([
            'found' => [
                ['kind' => 'Turkish identity number', 'action' => 'mask', 'value' => '10000000146'],
                ['kind' => 'Öğrenci no', 'action' => 'mask', 'value' => '201912345'],
            ],
            'sent' => 'TC [TCKN_1], öğrenci [OGRENCI_NO_1]',
        ]);

    expect(app(PersonalDataSettings::class)->rules['tckn'])->toBe('off')
        ->and(AuditLog::query()->count())->toBe(0);
});
