<?php

use App\Domain\AI\Enums\ProviderDriver;
use App\Domain\AI\Exceptions\ProviderAuthFailed;
use App\Domain\AI\Services\CredentialVault;
use App\Domain\AI\Services\ProviderManager;
use App\Models\AuditLog;
use App\Models\Provider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('keys are encrypted at rest and masked for display', function () {
    $provider = Provider::factory()->create();

    $credential = app(CredentialVault::class)->rotate($provider, ' sk-live-abcdef1234 ');

    $raw = DB::table('provider_credentials')->where('id', $credential->id)->value('secret');

    expect($raw)->not->toContain('sk-live')
        ->and($credential->fresh()->secret)->toBe('sk-live-abcdef1234')
        ->and(CredentialVault::mask($credential))->toBe('••••1234')
        ->and($credential->toArray())->not->toHaveKey('secret');
});

test('rotation keeps history, activates only the new key and is audited without the value', function () {
    $provider = Provider::factory()->create();
    $vault = app(CredentialVault::class);

    $old = $vault->rotate($provider, 'sk-old-key-0001');
    $new = $vault->rotate($provider, 'sk-new-key-0002');

    expect($old->fresh()->is_active)->toBeFalse()
        ->and($old->fresh()->rotated_at)->not->toBeNull()
        ->and($new->fresh()->is_active)->toBeTrue()
        ->and($provider->fresh()->activeCredential->id)->toBe($new->id);

    $logs = AuditLog::query()->where('action', 'provider.credential_rotated')->get();
    expect($logs)->toHaveCount(2)
        ->and(json_encode($logs->toArray()))->not->toContain('sk-new-key')
        ->and($logs->last()->new_values)->toBe(['last_four' => '0002']);
});

test('the stored key is used, otherwise the environment fallback', function () {
    config(['ada.providers.env_keys.anthropic' => 'env-anthropic-key']);
    Http::fake(['*' => Http::response(['data' => []])]);

    $provider = Provider::factory()->driver(ProviderDriver::Anthropic)->create();
    app(ProviderManager::class)->forProvider($provider)->checkConnection();
    Http::assertSent(fn (Request $r) => $r->hasHeader('x-api-key', 'env-anthropic-key'));

    app(CredentialVault::class)->rotate($provider, 'db-anthropic-key');
    app(ProviderManager::class)->forProvider($provider->fresh())->checkConnection();
    Http::assertSent(fn (Request $r) => $r->hasHeader('x-api-key', 'db-anthropic-key'));
});

test('a provider without any key cannot be used', function () {
    config(['ada.providers.env_keys.gemini' => null]);
    $provider = Provider::factory()->driver(ProviderDriver::Gemini)->create();

    expect(fn () => app(ProviderManager::class)->forProvider($provider))->toThrow(ProviderAuthFailed::class);
});

test('a custom base URL is honoured', function () {
    Http::fake(['*' => Http::response(['data' => []])]);
    $provider = Provider::factory()->create(['base_url' => 'https://llm.example.edu/v1/']);
    app(CredentialVault::class)->rotate($provider, 'sk-local');

    app(ProviderManager::class)->forProvider($provider->fresh())->checkConnection();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://llm.example.edu/v1/models');
});
