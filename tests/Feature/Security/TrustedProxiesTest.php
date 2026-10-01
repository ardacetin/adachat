<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;

function trustProxies(?string $proxies): void
{
    config(['ada.http.trusted_proxies' => $proxies]);
    (new AppServiceProvider(app()))->boot();
}

afterEach(function () {
    TrustProxies::flushState();
});

function viaProxy(string $proxy): array
{
    return [
        'REMOTE_ADDR' => $proxy,
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_PORT' => '443',
    ];
}

test('forwarded headers from a trusted proxy give the client address and https', function () {
    trustProxies('10.0.0.0/8, 192.168.1.1');

    $this->withServerVariables(viaProxy('10.1.2.3'))
        ->get('/login')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

    $user = User::factory()->superAdmin()->create();
    $this->actingAs($user)->withServerVariables(viaProxy('10.1.2.3'))
        ->put(route('admin.privacy.update'), ['acknowledgment_enabled' => false, 'acknowledgment_text' => null, 'conversation_retention_days' => null, 'deleted_conversation_days' => 30, 'usage_retention_months' => 24]);

    expect(AuditLog::query()->latest('id')->first()?->ip_address)->toBe('203.0.113.7');
});

test('forwarded headers from anyone else are ignored', function () {
    trustProxies('10.0.0.0/8');

    $this->withServerVariables(viaProxy('198.51.100.9'))
        ->get('/login')
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('no proxy is trusted by default', function () {
    trustProxies(null);

    $this->withServerVariables(viaProxy('127.0.0.1'))
        ->get('/login')
        ->assertHeaderMissing('Strict-Transport-Security');
});
