<?php

use App\Domain\Identity\Providers\OidcIdentityProvider;
use App\Domain\Institution\Settings\AuthSettings;
use App\Models\Group;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * The test plays the identity provider: it generates signing keys, serves
 * the discovery document, the key set and the token endpoint with
 * Http::fake, and signs the ID tokens Ada receives.
 */

const OIDC_TENANT = '7a1d2c3b-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
const OIDC_ISSUER = 'https://login.microsoftonline.com/'.OIDC_TENANT.'/v2.0';
const OIDC_CLIENT = '11111111-2222-3333-4444-555555555555';

function oidcBase64Url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * @return array{key: OpenSSLAsymmetricKey, jwk: array<string, string>}
 */
function oidcRsaKey(string $kid): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $details = openssl_pkey_get_details($key);

    return ['key' => $key, 'jwk' => [
        'kty' => 'RSA', 'use' => 'sig', 'kid' => $kid,
        'n' => oidcBase64Url($details['rsa']['n']), 'e' => oidcBase64Url($details['rsa']['e']),
    ]];
}

/**
 * @return array{key: OpenSSLAsymmetricKey, jwk: array<string, string>}
 */
function oidcEcKey(string $kid): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $details = openssl_pkey_get_details($key);

    return ['key' => $key, 'jwk' => [
        'kty' => 'EC', 'crv' => 'P-256', 'use' => 'sig', 'kid' => $kid,
        'x' => oidcBase64Url($details['ec']['x']), 'y' => oidcBase64Url($details['ec']['y']),
    ]];
}

/**
 * @param  array<string, mixed>  $claims
 * @param  array<string, mixed>  $header
 */
function oidcSign(array $claims, OpenSSLAsymmetricKey $key, array $header): string
{
    $input = oidcBase64Url(json_encode($header)).'.'.oidcBase64Url(json_encode($claims));

    if ($header['alg'] === 'none') {
        return $input.'.';
    }

    if ($header['alg'] === 'HS256') {
        return $input.'.'.oidcBase64Url(hash_hmac('sha256', $input, 'client-secret', true));
    }

    openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256);

    if ($header['alg'] === 'ES256') {
        // DER → r || s (32 bytes each).
        $offset = 3;
        $parts = [];

        for ($i = 0; $i < 2; $i++) {
            $length = ord($signature[$offset]);
            $parts[] = str_pad(ltrim(substr($signature, $offset + 1, $length), "\x00"), 32, "\x00", STR_PAD_LEFT);
            $offset += $length + 2;
        }

        $signature = implode('', $parts);
    }

    return $input.'.'.oidcBase64Url($signature);
}

beforeEach(function () {
    config(['app.url' => 'https://ada.example.edu']);
    config(['ada.auth.oidc' => [
        'enabled' => true,
        'label' => null,
        'issuer' => OIDC_ISSUER,
        'client_id' => OIDC_CLIENT,
        'client_secret' => 'client-secret',
        'preset' => 'entra',
        'scopes' => 'openid email profile',
    ]]);
    updateSettings(AuthSettings::class, ['allowed_domains' => ['example.edu'], 'auto_provision' => true]);

    $this->rsa = oidcRsaKey('rsa-1');
    $this->ec = oidcEcKey('ec-1');
    $this->jwks = ['keys' => [$this->rsa['jwk'], $this->ec['jwk']]];
    $this->idToken = null;
    $this->tokenStatus = 200;

    Http::fake(function (Request $request) {
        return match (true) {
            str_ends_with($request->url(), '/.well-known/openid-configuration') => Http::response([
                'issuer' => OIDC_ISSUER,
                'authorization_endpoint' => 'https://login.microsoftonline.com/'.OIDC_TENANT.'/oauth2/v2.0/authorize',
                'token_endpoint' => 'https://login.microsoftonline.com/'.OIDC_TENANT.'/oauth2/v2.0/token',
                'jwks_uri' => 'https://login.microsoftonline.com/'.OIDC_TENANT.'/discovery/v2.0/keys',
                'token_endpoint_auth_methods_supported' => ['client_secret_post', 'private_key_jwt', 'client_secret_basic'],
            ], 200, ['Date' => gmdate('D, d M Y H:i:s').' GMT']),
            str_ends_with($request->url(), '/keys') => Http::response($this->jwks),
            str_ends_with($request->url(), '/token') => Http::response(
                $this->tokenStatus === 200 ? ['access_token' => 'at', 'token_type' => 'Bearer', 'id_token' => $this->idToken] : ['error' => 'invalid_client'],
                $this->tokenStatus,
            ),
            default => Http::response(status: 404),
        };
    });
});

/**
 * Start sign-in and return the query Ada sent to the authorization endpoint.
 *
 * @return array<string, string>
 */
function startOidcSignIn(): array
{
    $location = test()->get(route('auth.redirect', 'oidc'))->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://login.microsoftonline.com/'.OIDC_TENANT.'/oauth2/v2.0/authorize?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

/**
 * Let the identity provider answer with an ID token for the given request.
 *
 * @param  array<string, string>  $query
 * @param  array<string, mixed>  $claims
 * @param  array<string, mixed>  $header
 */
function oidcCallback(array $query, array $claims = [], array $header = [], ?OpenSSLAsymmetricKey $key = null): TestResponse
{
    $test = test();
    $claims = array_filter(array_merge([
        'iss' => OIDC_ISSUER,
        'aud' => OIDC_CLIENT,
        'sub' => 'entra-subject-1',
        'tid' => OIDC_TENANT,
        'iat' => time(),
        'nbf' => time(),
        'exp' => time() + 3600,
        'nonce' => $query['nonce'],
        'name' => 'Ada Lovelace',
        'preferred_username' => 'ada@example.edu',
        'email' => 'ada@example.edu',
    ], $claims), fn ($value) => $value !== null);
    $header = array_merge(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'rsa-1'], $header);

    $test->idToken = oidcSign($claims, $key ?? ($header['alg'] === 'ES256' ? $test->ec['key'] : $test->rsa['key']), $header);

    return $test->get(route('auth.callback', ['provider' => 'oidc', 'code' => 'the-code', 'state' => $query['state']]));
}

test('the redirect asks for a code with state, nonce and PKCE', function () {
    $query = startOidcSignIn();

    expect($query)->toMatchArray([
        'response_type' => 'code',
        'client_id' => OIDC_CLIENT,
        'redirect_uri' => 'https://ada.example.edu/auth/oidc/callback',
        'scope' => 'openid email profile',
        'code_challenge_method' => 'S256',
    ])->and(strlen($query['state']))->toBe(40)
        ->and(strlen($query['nonce']))->toBe(40);
});

test('a valid ID token signs the user in', function () {
    $query = startOidcSignIn();

    oidcCallback($query)->assertRedirect(route('home'));

    $user = User::query()->sole();
    $identity = UserIdentity::query()->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->email)->toBe('ada@example.edu')
        ->and($user->name)->toBe('Ada Lovelace')
        ->and($identity->provider)->toBe('oidc')
        ->and($identity->subject)->toBe('entra-subject-1')
        ->and($identity->last_claims)->toMatchArray(['iss' => OIDC_ISSUER, 'tid' => OIDC_TENANT]);

    // The code was redeemed with the PKCE verifier and the client secret.
    Http::assertSent(function (Request $request) use ($query) {
        if (! str_ends_with($request->url(), '/token')) {
            return false;
        }

        return $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'the-code'
            && $request['redirect_uri'] === 'https://ada.example.edu/auth/oidc/callback'
            && oidcBase64Url(hash('sha256', $request['code_verifier'], true)) === $query['code_challenge']
            && $request->hasHeader('Authorization', 'Basic '.base64_encode(OIDC_CLIENT.':client-secret'));
    });
});

test('ES256 tokens are accepted too', function () {
    oidcCallback(startOidcSignIn(), header: ['alg' => 'ES256', 'kid' => 'ec-1'])->assertRedirect(route('home'));

    $this->assertAuthenticated();
});

test('a state can be used only once', function () {
    $query = startOidcSignIn();
    oidcCallback($query)->assertRedirect(route('home'));
    auth()->logout();

    oidcCallback($query)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['auth' => __('auth.errors.invalid_state')]);
});

test('a callback without a state Ada issued is refused', function () {
    startOidcSignIn();

    oidcCallback(['state' => 'forged', 'nonce' => 'forged'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['auth' => __('auth.errors.invalid_state')]);
    $this->assertGuest();
});

test('invalid ID tokens are refused', function (array $claims, array $header, bool $foreignKey) {
    $query = startOidcSignIn();

    oidcCallback($query, $claims, $header, $foreignKey ? oidcRsaKey('rsa-1')['key'] : null)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['auth' => __('auth.errors.provider_error')]);
    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
})->with([
    'signed with another key' => [[], [], true],
    'alg none' => [[], ['alg' => 'none'], false],
    'HMAC with the client secret' => [[], ['alg' => 'HS256'], false],
    'wrong issuer' => [['iss' => 'https://login.microsoftonline.com/00000000-0000-0000-0000-000000000000/v2.0'], [], false],
    'wrong audience' => [['aud' => 'another-app'], [], false],
    'several audiences without azp' => [['aud' => [OIDC_CLIENT, 'another-app']], [], false],
    'foreign authorized party' => [['azp' => 'another-app'], [], false],
    'expired' => [['exp' => time() - 120], [], false],
    'not yet valid' => [['nbf' => time() + 300], [], false],
    'issued in the future' => [['iat' => time() + 300], [], false],
    'wrong nonce' => [['nonce' => 'replayed'], [], false],
    'another tenant' => [['tid' => '00000000-0000-0000-0000-000000000000'], [], false],
    'no subject' => [['sub' => ''], [], false],
    'unknown key' => [[], ['kid' => 'rsa-unknown'], false],
    'critical extension' => [[], ['crit' => ['exp']], false],
]);

test('the key set is fetched again when the provider rotated its keys', function () {
    $query = startOidcSignIn();
    // Ada has cached the old key set.
    app(OidcIdentityProvider::class)->discovery()->keys();

    $rotated = oidcRsaKey('rsa-2');
    $this->jwks = ['keys' => [$rotated['jwk']]];

    oidcCallback($query, header: ['kid' => 'rsa-2'], key: $rotated['key'])->assertRedirect(route('home'));
});

test('an error from the identity provider is shown', function () {
    $query = startOidcSignIn();

    $this->get(route('auth.callback', ['provider' => 'oidc', 'state' => $query['state'], 'error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['auth' => __('auth.errors.provider_error')]);
});

test('a refused code redemption is shown', function () {
    $query = startOidcSignIn();
    $this->tokenStatus = 401;

    oidcCallback($query)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['auth' => __('auth.errors.provider_error')]);
});

test('Entra: the e-mail claim is used only when its domain is verified', function () {
    oidcCallback(startOidcSignIn(), ['email' => 'boss@example.edu', 'preferred_username' => 'ada@example.edu'])
        ->assertRedirect(route('home'));
    expect(User::query()->sole()->email)->toBe('ada@example.edu');

    auth()->logout();
    User::query()->delete();

    oidcCallback(startOidcSignIn(), ['sub' => 'entra-subject-2', 'email' => 'Grace@example.edu', 'xms_edov' => true, 'preferred_username' => 'g.hopper@example.edu'])
        ->assertRedirect(route('home'));
    expect(User::query()->sole()->email)->toBe('grace@example.edu');
});

test('Entra: guest accounts are refused', function (array $claims) {
    oidcCallback(startOidcSignIn(), $claims)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['auth' => __('auth.errors.provider_error')]);
})->with([
    'home directory elsewhere' => [['idp' => 'https://sts.windows.net/99999999-0000-0000-0000-000000000000/', 'preferred_username' => 'ada@example.edu']],
    'external user principal name' => [['preferred_username' => 'ada_gmail.com#EXT#@example.edu', 'email' => null]],
]);

test('generic: the e-mail must be verified', function (?bool $verified, bool $signedIn) {
    config(['ada.auth.oidc.preset' => 'generic', 'ada.auth.oidc.issuer' => OIDC_ISSUER]);

    $response = oidcCallback(startOidcSignIn(), ['tid' => null, 'email_verified' => $verified]);

    if ($signedIn) {
        $response->assertRedirect(route('home'));
    } else {
        $response->assertSessionHasErrors(['auth' => __('auth.errors.email_not_verified')]);
    }
})->with([
    'verified' => [true, true],
    'not verified' => [false, false],
    'claim missing' => [null, false],
]);

test('the groups claim maps the user to a group', function () {
    config(['ada.auth.oidc.groups_claim' => 'groups']);
    updateSettings(AuthSettings::class, ['group_mapping' => true, 'group_mapping_unmatched' => 'default']);
    $staff = Group::factory()->create(['idp_groups' => ['3f1c0d4e-0000-4000-8000-000000000001']]);

    oidcCallback(startOidcSignIn(), ['groups' => ['3f1c0d4e-0000-4000-8000-000000000001']])->assertRedirect(route('home'));
    $user = User::query()->sole();
    expect($user->group_id)->toBe($staff->id);
    auth()->logout();

    // Entra ID leaves the groups out when there are too many: nothing changes.
    oidcCallback(startOidcSignIn(), ['_claim_names' => ['groups' => 'src1']])->assertRedirect(route('home'));
    expect($user->refresh()->group_id)->toBe($staff->id);
    auth()->logout();

    // A token without the claim means no groups.
    oidcCallback(startOidcSignIn())->assertRedirect(route('home'));
    expect($user->refresh()->group_id)->toBe(Group::default()->id);
});

test('the e-mail domain policy still applies', function () {
    oidcCallback(startOidcSignIn(), ['preferred_username' => 'someone@other.edu'])
        ->assertSessionHasErrors(['auth' => __('auth.errors.domain_not_allowed')]);
});

test('sign-in is offered only when OIDC is configured correctly', function (array $config, ?string $problem) {
    config(['ada.auth.oidc' => array_merge(config('ada.auth.oidc'), $config)]);
    $provider = app(OidcIdentityProvider::class);

    expect($provider->configurationProblem())->toBe($problem)
        ->and($provider->isEnabled())->toBe($problem === null && ($config['enabled'] ?? true));
})->with([
    'complete' => [[], null],
    'turned off' => [['enabled' => false], null],
    'no client secret' => [['client_secret' => ''], 'OIDC_CLIENT_SECRET is not set.'],
    'multi-tenant endpoint' => [['issuer' => 'https://login.microsoftonline.com/common/v2.0'], 'With OIDC_PRESET=entra, OIDC_ISSUER must be https://login.microsoftonline.com/<tenant ID>/v2.0 (a single tenant; not common or organizations).'],
    'http issuer' => [['issuer' => 'http://idp.example.edu', 'preset' => 'generic'], null],
]);

test('the login page offers SAML and OIDC side by side', function () {
    config(['ada.auth.saml.idp_entity_id' => 'https://idp', 'ada.auth.saml.idp_sso_url' => 'https://idp/sso', 'ada.auth.saml.idp_x509_cert' => 'MIIB', 'ada.auth.saml.label' => 'Google']);

    $this->get(route('home'))->assertInertia(fn ($page) => $page->where('providers', [
        ['key' => 'saml', 'label' => 'Google'],
        ['key' => 'oidc', 'label' => 'Microsoft'],
    ]));
});

test('the callback routes belong to their protocol', function () {
    $this->post('/auth/oidc/acs')->assertNotFound();
    $this->get('/auth/saml/callback')->assertNotFound();
});

test('the admin sign-in page shows the OIDC setup without the secret', function () {
    $admin = User::factory()->superAdmin()->create();

    $response = $this->actingAs($admin)->get(route('admin.authentication.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('oidc.configured', true)
            ->where('oidc.preset', 'entra')
            ->where('oidc.issuer', OIDC_ISSUER)
            ->where('oidc.redirect_uri', 'https://ada.example.edu/auth/oidc/callback')
            ->where('oidc.client_id', '11111111••••••••••••••••••••••••••••'));

    expect($response->getContent())->not->toContain('client-secret');
});

test('administrators can test the OIDC connection', function () {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->post(route('admin.authentication.oidc-test'))
        ->assertRedirect(route('admin.authentication.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    config(['ada.auth.oidc.issuer' => 'https://login.microsoftonline.com/00000000-0000-0000-0000-000000000000/v2.0']);

    $this->post(route('admin.authentication.oidc-test'))->assertInertiaFlash('toast.type', 'error');

    $this->actingAs(User::factory()->admin()->create())->post(route('admin.authentication.oidc-test'))->assertForbidden();
});

test('the doctor checks the OIDC provider', function () {
    $this->artisan('ada:doctor')
        ->expectsOutputToContain('OpenID Connect settings are complete')
        ->expectsOutputToContain('OpenID Connect provider is reachable')
        ->expectsOutputToContain('Clock matches the identity provider');

    config(['ada.auth.oidc.issuer' => 'https://login.microsoftonline.com/common/v2.0']);

    $this->artisan('ada:doctor')->expectsOutputToContain('a single tenant; not common or organizations');
});
