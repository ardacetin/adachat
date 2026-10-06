<?php

use App\Domain\Identity\Actions\LoginUser;
use App\Domain\Identity\Data\ExternalIdentity;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityRejected;
use App\Domain\Identity\Exceptions\RejectionReason;
use App\Domain\Institution\Settings\AuthSettings;
use App\Models\User;
use App\Models\UserIdentity;

/*
 * With SAML and OIDC both enabled, an address the institution gives to
 * someone else must not hand over the former holder's account through the
 * provider that person never used (security audit, run 2).
 */

beforeEach(function () {
    updateSettings(AuthSettings::class, ['allowed_domains' => ['example.edu'], 'auto_provision' => true]);
});

function signInAs(string $provider, string $subject, string $email = 'x@example.edu', ?string $previous = null): User
{
    return app(LoginUser::class)->handle(new ExternalIdentity($provider, $subject, $email, true, null, 'Someone', null, [], $previous), requireHostedDomain: false);
}

function rejection(Closure $signIn): ?RejectionReason
{
    try {
        $signIn();
    } catch (IdentityRejected $rejected) {
        return $rejected->reason;
    }

    return null;
}

test('an account bound to a stable subject is not claimed through the other provider', function () {
    $former = User::factory()->superAdmin()->create(['email' => 'x@example.edu']);
    UserIdentity::query()->forceCreate(['user_id' => $former->id, 'provider' => 'saml', 'subject' => 'EMP-0001', 'email' => 'x@example.edu']);

    expect(rejection(fn () => signInAs('oidc', 'new-oid')))->toBe(RejectionReason::AccountConflict)
        ->and(rejection(fn () => signInAs('saml', 'EMP-0999')))->toBe(RejectionReason::AccountConflict)
        ->and(UserIdentity::query()->count())->toBe(1);

    $oidcOnly = User::factory()->admin()->create(['email' => 'y@example.edu']);
    UserIdentity::query()->forceCreate(['user_id' => $oidcOnly->id, 'provider' => 'oidc', 'subject' => 'oid-1', 'email' => 'y@example.edu']);

    expect(rejection(fn () => signInAs('saml', 'EMP-0777', 'y@example.edu', previous: 'y@example.edu')))->toBe(RejectionReason::AccountConflict);
});

test('accounts nobody signed in to yet, and e-mail-keyed SAML accounts, are still linked', function () {
    $added = User::factory()->create(['email' => 'new@example.edu']);
    expect(signInAs('oidc', 'oid-2', 'new@example.edu')->is($added))->toBeTrue();

    // Known only by its SAML e-mail address: moving to Entra ID keeps it.
    $saml = User::factory()->create(['email' => 'move@example.edu']);
    UserIdentity::query()->forceCreate(['user_id' => $saml->id, 'provider' => 'saml', 'subject' => 'move@example.edu', 'email' => 'move@example.edu']);
    expect(signInAs('oidc', 'oid-3', 'move@example.edu')->is($saml))->toBeTrue();
});

test('a disabled account gets no new sign-in method', function () {
    User::factory()->create(['email' => 'gone@example.edu', 'status' => UserStatus::Disabled]);

    expect(rejection(fn () => signInAs('oidc', 'oid-4', 'gone@example.edu')))->toBe(RejectionReason::AccountDisabled)
        ->and(UserIdentity::query()->count())->toBe(0);
});
