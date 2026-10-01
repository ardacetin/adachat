<?php

use App\Domain\AI\Services\CredentialVault;
use App\Models\AuditLog;
use App\Models\Provider;
use App\Models\ProviderCredential;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

function useAppKey(string $key, array $previous = []): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function newAppKey(): string
{
    return 'base64:'.base64_encode(Encrypter::generateKey('aes-256-cbc'));
}

test('after a key rotation, provider keys are re-encrypted with the new key', function () {
    $old = newAppKey();
    $new = newAppKey();
    useAppKey($old);
    $credential = app(CredentialVault::class)->rotate(Provider::factory()->create(), 'sk-secret-1234');

    useAppKey($new, [$old]);
    $this->artisan('ada:credentials:reencrypt')
        ->expectsOutputToContain('Re-encrypted 1 provider API key(s)')
        ->doesntExpectOutputToContain('sk-secret')
        ->assertSuccessful();

    // The old key is no longer needed.
    useAppKey($new);
    expect(ProviderCredential::query()->findOrFail($credential->id)->secret)->toBe('sk-secret-1234')
        ->and(AuditLog::query()->where('action', 'credentials.reencrypted')->sole()->new_values)->toBe(['count' => 1]);
});

test('keys that no configured key can decrypt are reported, not lost', function () {
    useAppKey(newAppKey());
    $credential = app(CredentialVault::class)->rotate(Provider::factory()->create(['name' => 'OpenAI']), 'sk-secret-9876');
    $raw = $credential->getRawOriginal('secret');

    useAppKey(newAppKey());
    $this->artisan('ada:credentials:reencrypt')
        ->expectsOutputToContain('OpenAI, ••••9876')
        ->assertFailed();

    expect(ProviderCredential::query()->findOrFail($credential->id)->getRawOriginal('secret'))->toBe($raw);
});
