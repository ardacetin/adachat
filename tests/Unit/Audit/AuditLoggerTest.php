<?php

use App\Domain\Audit\AuditLogger;

test('secret-looking keys are redacted recursively', function () {
    $redacted = AuditLogger::redact([
        'name' => 'Example',
        'api_key' => 'sk-live-123',
        'client_secret' => 'abc',
        'nested' => ['password' => 'x', 'access_token' => 'y', 'domain' => 'example.edu'],
    ]);

    expect($redacted)->toBe([
        'name' => 'Example',
        'api_key' => AuditLogger::REDACTED,
        'client_secret' => AuditLogger::REDACTED,
        'nested' => ['password' => AuditLogger::REDACTED, 'access_token' => AuditLogger::REDACTED, 'domain' => 'example.edu'],
    ]);
});

test('diff keeps only changed values', function () {
    [$old, $new] = AuditLogger::diff(
        ['name' => 'A', 'color' => null, 'domains' => ['a.edu']],
        ['name' => 'A', 'color' => '#1e40af', 'domains' => ['a.edu', 'b.edu']],
    );

    expect($old)->toBe(['color' => null, 'domains' => ['a.edu']])
        ->and($new)->toBe(['color' => '#1e40af', 'domains' => ['a.edu', 'b.edu']]);
});
