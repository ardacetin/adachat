<?php

use App\Domain\Identity\Exceptions\IdentityRejected;
use App\Domain\Identity\Exceptions\RejectionReason;
use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Domain\Institution\Settings\AuthSettings;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Models\Group;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeIdentityProvider;

beforeEach(function () {
    updateSettings(AuthSettings::class, ['allowed_domains' => ['example.edu'], 'auto_provision' => true]);

    $this->idp = new FakeIdentityProvider;
    $this->app->instance(IdentityProviderRegistry::class, new IdentityProviderRegistry([$this->idp]));
});

function signInCallback(): TestResponse
{
    return test()->get(route('auth.callback', 'google'));
}

test('unknown providers are not found', function () {
    $this->get(route('auth.redirect', 'saml-unknown'))->assertNotFound();
    $this->get(route('auth.callback', 'saml-unknown'))->assertNotFound();
});

test('the redirect is delegated to the provider', function () {
    $this->get(route('auth.redirect', 'google'))->assertRedirect('https://idp.test/authorize');
});

test('the login page lists enabled providers', function () {
    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('providers', ['google']));
});

test('a first sign-in provisions the user in the default group', function () {
    signInCallback()->assertRedirect(route('home'));

    $user = User::query()->where('email', 'ada@example.edu')->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->role->value)->toBe('user')
        ->and($user->group_id)->toBe(Group::default()->id)
        ->and($user->name)->toBe('Ada Lovelace')
        ->and($user->last_login_at)->not->toBeNull();

    $identity = UserIdentity::query()->sole();
    expect($identity->user_id)->toBe($user->id)
        ->and($identity->provider)->toBe('google')
        ->and($identity->subject)->toBe('google-subject-1')
        ->and($identity->last_claims)->toBe(['hd' => 'example.edu', 'email_verified' => true]);
});

test('returning users are matched by subject even after an e-mail change', function () {
    signInCallback();
    auth()->logout();

    $this->idp->next = FakeIdentityProvider::identity(['email' => 'ada.lovelace@example.edu', 'name' => 'Ada L.']);
    signInCallback()->assertRedirect(route('home'));

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->sole()->email)->toBe('ada.lovelace@example.edu')
        ->and(UserIdentity::query()->sole()->email)->toBe('ada.lovelace@example.edu');
});

test('pre-created users are linked by verified e-mail on first sign-in', function () {
    $admin = User::factory()->superAdmin()->create(['email' => 'ada@example.edu']);

    signInCallback()->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($admin);
    expect($admin->fresh()->role->value)->toBe('super_admin')
        ->and(UserIdentity::query()->sole()->user_id)->toBe($admin->id);
});

test('rejections are shown translated and nobody is signed in', function (RejectionReason $reason, string $expected) {
    $this->idp->next = new IdentityRejected($reason);

    $this->withHeader('Accept-Language', 'tr')
        ->from(route('login'));
    updateSettings(InstitutionSettings::class, ['default_locale' => 'tr']);

    signInCallback()
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['auth' => $expected]);

    $this->assertGuest();
})->with([
    'invalid state' => [RejectionReason::InvalidState, 'Giriş oturumunuzun süresi doldu. Lütfen tekrar deneyin.'],
    'provider error' => [RejectionReason::ProviderError, 'Kimlik sağlayıcınızla giriş başarısız oldu. Lütfen tekrar deneyin.'],
]);

test('accounts from other domains are rejected', function () {
    $this->idp->next = FakeIdentityProvider::identity(['email' => 'eve@other.edu', 'hostedDomain' => 'other.edu']);

    signInCallback()->assertSessionHasErrors('auth');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('personal Google accounts are rejected', function () {
    updateSettings(AuthSettings::class, ['allowed_domains' => ['gmail.com']]);
    $this->idp->next = FakeIdentityProvider::identity(['email' => 'eve@gmail.com', 'hostedDomain' => null]);

    signInCallback()->assertSessionHasErrors('auth');

    $this->assertGuest();
});

test('unverified e-mail addresses are rejected', function () {
    $this->idp->next = FakeIdentityProvider::identity(['emailVerified' => false]);

    signInCallback()->assertSessionHasErrors('auth');

    $this->assertGuest();
});

test('disabled users cannot sign in', function () {
    User::factory()->disabled()->create(['email' => 'ada@example.edu']);

    signInCallback()->assertSessionHasErrors(['auth' => __('auth.errors.account_disabled')]);

    $this->assertGuest();
});

test('without auto provisioning unknown users are rejected', function () {
    updateSettings(AuthSettings::class, ['auto_provision' => false]);

    signInCallback()->assertSessionHasErrors(['auth' => __('auth.errors.not_provisioned')]);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('an e-mail linked to another account at the same provider is a conflict', function () {
    signInCallback();
    auth()->logout();

    // A different Google account (new subject) claiming the same address.
    $this->idp->next = FakeIdentityProvider::identity(['subject' => 'google-subject-2']);

    signInCallback()->assertSessionHasErrors(['auth' => __('auth.errors.account_conflict')]);

    $this->assertGuest();
    expect(User::query()->count())->toBe(1);
});

test('the session is regenerated on sign-in', function () {
    $this->startSession();
    $before = session()->getId();

    signInCallback();

    expect(session()->getId())->not->toBe($before);
});
