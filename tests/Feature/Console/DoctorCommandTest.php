<?php

use App\Domain\AI\Services\CredentialVault;
use App\Domain\Institution\Settings\AuthSettings;
use App\Models\Group;
use App\Models\ModelAlias;
use App\Models\Provider;
use Illuminate\Support\Facades\Cache;

test('the doctor reports problems and fails', function () {
    config(['ada.auth.saml.idp_x509_cert' => null]);

    $this->artisan('ada:doctor')
        ->expectsOutputToContain('Scheduler ran in the last 5 minutes')
        ->expectsOutputToContain('FAIL')
        ->assertFailed();
});

test('a healthy installation passes', function () {
    config([
        'cache.default' => 'database',
        'ada.auth.saml' => [
            'idp_entity_id' => 'https://accounts.google.com/o/saml2?idpid=X',
            'idp_sso_url' => 'https://accounts.google.com/o/saml2/idp?idpid=X',
            'idp_x509_cert' => 'MIIBtest',
            'idp_x509_cert_path' => null,
            'attributes' => [],
            'label' => 'Google',
        ],
    ]);
    updateSettings(AuthSettings::class, ['allowed_domains' => ['university.edu']]);
    Cache::forever('ada:scheduler:heartbeat', now()->toIso8601String());
    $provider = Provider::factory()->create(['enabled' => true]);
    app(CredentialVault::class)->rotate($provider, 'sk-test-1234');
    ModelAlias::factory()->create()->groups()->attach(Group::default());

    $this->artisan('ada:doctor')->assertSuccessful();
});

test('in production the doctor warns about a log file that never rotates', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['logging.default' => 'stack', 'logging.channels.stack.channels' => ['single']]);

    $this->artisan('ada:doctor')->expectsOutputToContain('Set LOG_CHANNEL=json');

    config(['logging.default' => 'json']);

    $this->artisan('ada:doctor')->doesntExpectOutputToContain('Set LOG_CHANNEL=json');
});
