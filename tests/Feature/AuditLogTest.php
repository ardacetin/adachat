<?php

use App\Domain\Audit\AuditLogger;
use App\Models\AuditLog;
use App\Models\User;

test('audit logs are append-only', function () {
    $log = app(AuditLogger::class)->record('test.action', null, [], ['a' => 1]);

    expect(fn () => $log->forceFill(['action' => 'changed'])->save())->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class)
        ->and(AuditLog::query()->sole()->action)->toBe('test.action');
});

test('the actor and request metadata are recorded', function () {
    $user = User::factory()->superAdmin()->create();
    $this->actingAs($user);

    $log = app(AuditLogger::class)->record('user.role_changed', $user, ['role' => 'user'], ['role' => 'admin', 'token' => 'x']);

    expect($log->actor_id)->toBe($user->id)
        ->and($log->actor_type)->toBe('user')
        ->and($log->subject_type)->toBe('User')
        ->and($log->subject_id)->toBe((string) $user->id)
        ->and($log->new_values)->toEqual(['role' => 'admin', 'token' => AuditLogger::REDACTED]);
});

test('ada:user:promote is audited as a CLI action', function () {
    $this->artisan('ada:user:promote', ['email' => 'root@example.edu'])->assertSuccessful();

    $log = AuditLog::query()->sole();
    expect($log->action)->toBe('user.created')
        ->and($log->actor_type)->toBe('cli')
        ->and($log->new_values)->toEqual(['role' => 'super_admin', 'via' => 'ada:user:promote']);
});
