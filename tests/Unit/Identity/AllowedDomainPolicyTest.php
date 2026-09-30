<?php

use App\Domain\Identity\Exceptions\IdentityRejected;
use App\Domain\Identity\Exceptions\RejectionReason;
use App\Domain\Identity\Services\AllowedDomainPolicy;
use Tests\Support\FakeIdentityProvider;

function rejectionFor(Closure $callback): ?RejectionReason
{
    try {
        $callback();
    } catch (IdentityRejected $rejection) {
        return $rejection->reason;
    }

    return null;
}

test('a verified workspace account from an allowed domain passes', function () {
    $policy = new AllowedDomainPolicy(['example.edu']);

    expect(rejectionFor(fn () => $policy->assertAllowed(FakeIdentityProvider::identity(), true)))->toBeNull();
});

test('domains are compared case-insensitively', function () {
    $policy = new AllowedDomainPolicy(['example.edu']);
    $identity = FakeIdentityProvider::identity(['email' => 'Ada@Example.EDU', 'hostedDomain' => 'EXAMPLE.edu']);

    expect(rejectionFor(fn () => $policy->assertAllowed($identity, true)))->toBeNull();
});

test('unverified e-mail addresses are rejected', function () {
    $policy = new AllowedDomainPolicy(['example.edu']);
    $identity = FakeIdentityProvider::identity(['emailVerified' => false]);

    expect(rejectionFor(fn () => $policy->assertAllowed($identity, true)))->toBe(RejectionReason::EmailNotVerified);
});

test('e-mail domains outside the allow list are rejected', function () {
    $policy = new AllowedDomainPolicy(['example.edu']);
    $identity = FakeIdentityProvider::identity(['email' => 'ada@other.edu', 'hostedDomain' => 'other.edu']);

    expect(rejectionFor(fn () => $policy->assertAllowed($identity, true)))->toBe(RejectionReason::DomainNotAllowed);
});

test('personal accounts without a hosted domain are rejected', function () {
    // Even if someone misconfigured gmail.com as an allowed domain.
    $policy = new AllowedDomainPolicy(['gmail.com']);
    $identity = FakeIdentityProvider::identity(['email' => 'ada@gmail.com', 'hostedDomain' => null]);

    expect(rejectionFor(fn () => $policy->assertAllowed($identity, true)))->toBe(RejectionReason::DomainNotAllowed);
});

test('a hosted domain that is not allowed is rejected even if the e-mail domain is', function () {
    $policy = new AllowedDomainPolicy(['example.edu']);
    $identity = FakeIdentityProvider::identity(['hostedDomain' => 'other.edu']);

    expect(rejectionFor(fn () => $policy->assertAllowed($identity, true)))->toBe(RejectionReason::DomainNotAllowed);
});

test('the hosted domain is not required for providers that do not issue it', function () {
    $policy = new AllowedDomainPolicy(['example.edu']);
    $identity = FakeIdentityProvider::identity(['hostedDomain' => null]);

    expect(rejectionFor(fn () => $policy->assertAllowed($identity, false)))->toBeNull();
});

test('an empty allow list rejects everyone', function () {
    $policy = new AllowedDomainPolicy([]);

    expect(rejectionFor(fn () => $policy->assertAllowed(FakeIdentityProvider::identity(), true)))
        ->toBe(RejectionReason::DomainNotAllowed);
});
