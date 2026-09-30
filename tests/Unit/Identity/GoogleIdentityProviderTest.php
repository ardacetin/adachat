<?php

use App\Domain\Identity\Exceptions\IdentityRejected;
use App\Domain\Identity\Exceptions\RejectionReason;
use App\Domain\Identity\Providers\GoogleIdentityProvider;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Two\User as SocialiteUser;

function googleUser(array $claims): SocialiteUser
{
    return (new SocialiteUser)->setRaw($claims);
}

function googleProvider(): GoogleIdentityProvider
{
    return new GoogleIdentityProvider(Mockery::mock(Factory::class), ['example.edu']);
}

test('userinfo claims are mapped to an external identity', function () {
    $identity = googleProvider()->toExternalIdentity(googleUser([
        'sub' => '1234567890',
        'email' => 'Ada@Example.edu',
        'email_verified' => true,
        'hd' => 'example.edu',
        'name' => 'Ada Lovelace',
        'picture' => 'https://lh3.googleusercontent.com/a/ada',
    ]));

    expect($identity->provider)->toBe('google')
        ->and($identity->subject)->toBe('1234567890')
        ->and($identity->email)->toBe('ada@example.edu')
        ->and($identity->emailVerified)->toBeTrue()
        ->and($identity->hostedDomain)->toBe('example.edu')
        ->and($identity->name)->toBe('Ada Lovelace')
        ->and($identity->safeClaims)->toBe(['hd' => 'example.edu', 'email_verified' => true]);
});

test('email_verified must be the boolean true', function (mixed $value) {
    $identity = googleProvider()->toExternalIdentity(googleUser([
        'sub' => '1', 'email' => 'ada@example.edu', 'email_verified' => $value,
    ]));

    expect($identity->emailVerified)->toBeFalse();
})->with(['string "true"' => 'true', 'missing' => null, 'false' => false, 'one' => 1]);

test('personal accounts have no hosted domain', function () {
    $identity = googleProvider()->toExternalIdentity(googleUser([
        'sub' => '1', 'email' => 'ada@gmail.com', 'email_verified' => true,
    ]));

    expect($identity->hostedDomain)->toBeNull();
});

test('identities without a subject or e-mail are rejected', function (array $claims) {
    $reason = null;

    try {
        googleProvider()->toExternalIdentity(googleUser($claims));
    } catch (IdentityRejected $rejection) {
        $reason = $rejection->reason;
    }

    expect($reason)->toBe(RejectionReason::ProviderError);
})->with([
    'no subject' => [['email' => 'ada@example.edu', 'email_verified' => true]],
    'no e-mail' => [['sub' => '1', 'email_verified' => true]],
]);
