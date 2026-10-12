<?php

use App\Domain\Identity\Exceptions\IdentityRejected;
use App\Domain\Identity\Exceptions\RejectionReason;
use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\FakeIdentityProvider;

test('guests see the landing page and are sent to sign in from everywhere else', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome')->has('providers')->where('auth.user', null));

    $this->get(route('usage'))->assertRedirect(route('login'));
});

test('sign-in starts on the landing page: /login leads there with its error', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome')->has('providers')->has('devLoginUsers'));

    // A refused sign-in returns through /login to the landing page, with
    // the reason still there to show.
    $idp = new FakeIdentityProvider;
    $idp->next = new IdentityRejected(RejectionReason::DomainNotAllowed);
    $this->app->instance(IdentityProviderRegistry::class, new IdentityProviderRegistry([$idp]));

    $this->followingRedirects()
        ->post(route('auth.acs', 'saml'))
        ->assertInertia(fn ($page) => $page->component('welcome')
            ->where('errors.auth', __('auth.errors.domain_not_allowed')));

    // The page a guest wanted is kept for after sign-in.
    $this->get(route('usage'))->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe(route('usage'));
});

test('authenticated users land on the chat', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('chat/index'));
});

test('users can log out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();
});

test('disabled users are logged out on their next request', function () {
    $user = User::factory()->create();

    $this->actingAs($user);
    $user->forceFill(['status' => 'disabled', 'disabled_at' => now()])->save();

    $this->get(route('home'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('auth');

    $this->assertGuest();
});

test('shared props expose only the public user fields', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.name', 'Ada Lovelace')
            ->where('auth.user.role', 'user')
            ->missing('auth.user.remember_token')
            ->missing('auth.user.created_at'));
});

test('with a single identity provider /login goes straight to it', function () {
    config(['ada.auth.dev_login' => false]);
    $this->app->instance(IdentityProviderRegistry::class, new IdentityProviderRegistry([new FakeIdentityProvider]));

    $this->get(route('login'))->assertRedirect(route('auth.redirect', 'saml'));

    // An Inertia visit (an expired session while using the app) gets a full-page redirect.
    $version = (string) app(HandleInertiaRequests::class)->version(request());
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->get(route('login'))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('auth.redirect', 'saml'));
});

test('/login stays on the landing page after a refusal, with several providers or with the development login', function () {
    $this->app->instance(IdentityProviderRegistry::class, new IdentityProviderRegistry([new FakeIdentityProvider]));

    // The development login (local and tests) is chosen on the landing page.
    $this->get(route('login'))->assertRedirect(route('home'));

    config(['ada.auth.dev_login' => false]);

    // A refusal: the provider would sign the user in and be refused again.
    $this->withSession(['errors' => new ViewErrorBag])
        ->get(route('login'))
        ->assertRedirect(route('home'));

    $this->app->instance(IdentityProviderRegistry::class, new IdentityProviderRegistry([
        new FakeIdentityProvider('saml'),
        new FakeIdentityProvider('oidc'),
    ]));
    $this->flushSession();
    $this->get(route('login'))->assertRedirect(route('home'));
});
