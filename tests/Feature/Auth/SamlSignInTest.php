<?php

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Providers\SamlIdentityProvider;
use App\Domain\Institution\Settings\AuthSettings;
use App\Models\Group;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Testing\TestResponse;
use OneLogin\Saml2\Utils;

/*
 * The test plays the identity provider: it generates a signing key and
 * certificate, answers Ada's real AuthnRequest with a signed SAML response
 * and posts it to the ACS, exercising the full validation path.
 */

const IDP_ENTITY = 'https://accounts.google.com/o/saml2?idpid=TEST123';
const IDP_SSO = 'https://accounts.google.com/o/saml2/idp?idpid=TEST123';

/**
 * @return array{key: string, cert: string}
 */
function idpKeyPair(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'Test IdP'], $key);
    $cert = openssl_csr_sign($csr, null, $key, 365);
    openssl_pkey_export($key, $keyPem);
    openssl_x509_export($cert, $certPem);

    return ['key' => $keyPem, 'cert' => $certPem];
}

beforeEach(function () {
    config(['app.url' => 'https://ada.example.edu']);
    $this->idp = idpKeyPair();
    config(['ada.auth.saml' => [
        'idp_entity_id' => IDP_ENTITY,
        'idp_sso_url' => IDP_SSO,
        'idp_x509_cert' => $this->idp['cert'],
        'idp_x509_cert_path' => null,
        'attributes' => ['first_name' => 'first_name', 'last_name' => 'last_name'],
        'label' => 'Google',
    ]]);
});

/**
 * Start sign-in and return the AuthnRequest ID Ada sent to the IdP.
 */
function startSignIn(): string
{
    $redirect = test()->get(route('auth.redirect', 'saml'))->assertRedirect();
    $location = $redirect->headers->get('Location');

    // The browser keeps the binding cookie and sends it with the ACS POST.
    $cookie = $redirect->getCookie(SamlIdentityProvider::BINDING_COOKIE, decrypt: true);
    expect($cookie->getPath())->toBe('/auth/saml/acs')
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('none');
    test()->withCookie(SamlIdentityProvider::BINDING_COOKIE, (string) $cookie->getValue());

    expect($location)->toStartWith(IDP_SSO);
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $request = gzinflate(base64_decode($query['SAMLRequest']));

    expect($request)->toContain('AssertionConsumerServiceURL="https://ada.example.edu/auth/saml/acs"')
        ->toContain('<saml:Issuer>https://ada.example.edu/auth/saml/metadata</saml:Issuer>');

    preg_match('/ID="([^"]+)"/', $request, $matches);

    return $matches[1];
}

/**
 * A SAML response as Google would send it; signed over the whole response.
 *
 * @param  array<string, mixed>  $overrides
 */
function samlResponse(?string $inResponseTo, array $overrides = []): string
{
    $o = array_merge([
        'email' => 'ada@example.edu',
        'audience' => 'https://ada.example.edu/auth/saml/metadata',
        'destination' => 'https://ada.example.edu/auth/saml/acs',
        'issuer' => IDP_ENTITY,
        'notOnOrAfter' => gmdate('Y-m-d\TH:i:s\Z', time() + 300),
        'key' => test()->idp['key'],
        'cert' => test()->idp['cert'],
        'sign' => true,
        'signAssertion' => false,
        'tamper' => null,
        'employee_id' => null,
        'groups' => null,
    ], $overrides);

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $inResponse = $inResponseTo !== null ? ' InResponseTo="'.$inResponseTo.'"' : '';
    $id = '_'.bin2hex(random_bytes(16));
    $assertionId = '_'.bin2hex(random_bytes(16));
    $employee = $o['employee_id'] === null ? '' : '<saml:Attribute Name="employee_id"><saml:AttributeValue>'.$o['employee_id'].'</saml:AttributeValue></saml:Attribute>';
    $employee .= $o['groups'] === null ? '' : '<saml:Attribute Name="groups">'.implode('', array_map(fn (string $group) => '<saml:AttributeValue>'.$group.'</saml:AttributeValue>', $o['groups'])).'</saml:Attribute>';

    $xml = <<<XML
<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$id}" Version="2.0" IssueInstant="{$now}" Destination="{$o['destination']}"{$inResponse}><saml:Issuer>{$o['issuer']}</saml:Issuer><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status><saml:Assertion ID="{$assertionId}" Version="2.0" IssueInstant="{$now}"><saml:Issuer>{$o['issuer']}</saml:Issuer><saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">{$o['email']}</saml:NameID><saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData{$inResponse} NotOnOrAfter="{$o['notOnOrAfter']}" Recipient="{$o['destination']}"/></saml:SubjectConfirmation></saml:Subject><saml:Conditions NotBefore="{$now}" NotOnOrAfter="{$o['notOnOrAfter']}"><saml:AudienceRestriction><saml:Audience>{$o['audience']}</saml:Audience></saml:AudienceRestriction></saml:Conditions><saml:AttributeStatement><saml:Attribute Name="first_name"><saml:AttributeValue>Ada</saml:AttributeValue></saml:Attribute><saml:Attribute Name="last_name"><saml:AttributeValue>Lovelace</saml:AttributeValue></saml:Attribute>{$employee}</saml:AttributeStatement><saml:AuthnStatement AuthnInstant="{$now}" SessionIndex="{$assertionId}"><saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:unspecified</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement></saml:Assertion></samlp:Response>
XML;

    if ($o['signAssertion']) {
        // Google's default: only the assertion is signed.
        preg_match('#<saml:Assertion .*</saml:Assertion>#s', $xml, $match);
        $assertion = str_replace('<saml:Assertion ', '<saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ', $match[0]);
        $document = new DOMDocument;
        $document->loadXML(Utils::addSign($assertion, $o['key'], $o['cert']));
        // The schema wants the signature right after the Issuer (as Google sends it);
        // moving it does not change the digest (enveloped-signature transform).
        $signature = $document->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->item(0);
        $issuer = $document->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Issuer')->item(0);
        $document->documentElement->insertBefore($signature, $issuer->nextSibling);
        $xml = str_replace($match[0], $document->saveXML($document->documentElement), $xml);
    } elseif ($o['sign']) {
        $xml = Utils::addSign($xml, $o['key'], $o['cert']);
    }

    if ($o['tamper'] !== null) {
        $xml = str_replace('ada@example.edu', $o['tamper'], $xml);
    }

    return base64_encode($xml);
}

function postAcs(string $response): TestResponse
{
    return test()->post(route('auth.acs', 'saml'), ['SAMLResponse' => $response]);
}

test('a signed response for Ada\'s request signs the user in', function () {
    $requestId = startSignIn();

    postAcs(samlResponse($requestId))->assertRedirect(route('home'));

    $user = User::query()->sole();
    $identity = UserIdentity::query()->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->email)->toBe('ada@example.edu')
        ->and($user->name)->toBe('Ada Lovelace')
        ->and($identity->provider)->toBe('saml')
        ->and($identity->subject)->toBe('ada@example.edu')
        ->and($identity->last_claims['idp'])->toBe(IDP_ENTITY);
});

test('a response with only the assertion signed is accepted', function () {
    $requestId = startSignIn();

    postAcs(samlResponse($requestId, ['signAssertion' => true]))->assertRedirect(route('home'));

    $this->assertAuthenticated();
});

test('a response can be used only once', function () {
    $requestId = startSignIn();
    $response = samlResponse($requestId);

    postAcs($response)->assertRedirect(route('home'));
    auth()->logout();

    postAcs($response)->assertRedirect(route('login'))->assertSessionHasErrors('auth');
    $this->assertGuest();
});

test('responses to requests Ada never made are refused', function () {
    startSignIn();

    postAcs(samlResponse('_not-our-request'))->assertRedirect(route('login'))->assertSessionHasErrors('auth');
    $this->assertGuest();
});

test('a response is accepted only in the browser that started the sign-in', function () {
    // Another browser sends its own binding value: refused.
    $response = samlResponse(startSignIn());
    $this->withCookie(SamlIdentityProvider::BINDING_COOKIE, str_repeat('x', 40));
    postAcs($response)->assertRedirect(route('login'))->assertSessionHasErrors('auth');

    // Or none at all: refused too.
    $response = samlResponse(startSignIn());
    $this->defaultCookies = [];
    postAcs($response)->assertRedirect(route('login'))->assertSessionHasErrors('auth');

    $this->assertGuest();
});

test('sign-ins started in two tabs of one browser both complete', function () {
    $first = samlResponse(startSignIn());
    $second = samlResponse(startSignIn());

    postAcs($first)->assertRedirect(route('home'));
    auth()->logout();
    postAcs($second)->assertRedirect(route('home'));
    $this->assertAuthenticated();
});

/**
 * Sign in through a full SP-initiated round trip and sign out again.
 *
 * @param  array<string, mixed>  $overrides
 */
function samlSignIn(array $overrides): TestResponse
{
    $response = postAcs(samlResponse(startSignIn(), $overrides));
    auth()->logout();

    return $response;
}

test('without a stable ID a reused address signs into the previous holder\'s account', function () {
    samlSignIn([])->assertRedirect(route('home'));
    $first = User::query()->sole();

    // The address is given to another person: Ada cannot tell them apart.
    samlSignIn([])->assertRedirect(route('home'));
    expect(User::query()->count())->toBe(1)
        ->and(UserIdentity::query()->sole()->user_id)->toBe($first->id);

    // Which is why leavers' accounts are disabled: a disabled account is refused.
    $first->forceFill(['status' => UserStatus::Disabled])->save();
    samlSignIn([])->assertRedirect(route('login'))->assertSessionHasErrors('auth');
});

test('with a stable ID a reused address does not reach the previous holder\'s account', function () {
    config(['ada.auth.saml.attributes.subject' => 'employee_id']);

    samlSignIn(['employee_id' => 'E-1001'])->assertRedirect(route('home'));
    $first = User::query()->sole();
    expect(UserIdentity::query()->sole()->subject)->toBe('E-1001');

    // Someone else now holds the address.
    samlSignIn(['employee_id' => 'E-2002'])->assertRedirect(route('login'))->assertSessionHasErrors('auth');
    $this->assertGuest();
    expect(User::query()->count())->toBe(1);

    // The first person, renamed at the IdP, keeps their account.
    samlSignIn(['employee_id' => 'E-1001', 'email' => 'lovelace@example.edu'])->assertRedirect(route('home'));
    expect(User::query()->sole()->id)->toBe($first->id)
        ->and($first->refresh()->email)->toBe('lovelace@example.edu');
});

test('accounts stored under the address move to the stable ID on their next sign-in', function () {
    // An account from before the stable ID was configured.
    $user = User::factory()->create(['email' => 'ada@example.edu']);
    (new UserIdentity)->forceFill(['user_id' => $user->id, 'provider' => 'saml', 'subject' => 'ada@example.edu', 'email' => 'ada@example.edu'])->save();

    config(['ada.auth.saml.attributes.subject' => 'employee_id']);
    samlSignIn(['employee_id' => 'E-1001'])->assertRedirect(route('home'));

    expect(User::query()->sole()->id)->toBe($user->id)
        ->and(UserIdentity::query()->sole()->subject)->toBe('E-1001');
});

test('a configured stable ID must be in the response', function () {
    config(['ada.auth.saml.attributes.subject' => 'employee_id']);

    samlSignIn([])->assertRedirect(route('login'))->assertSessionHasErrors('auth');
    expect(User::query()->count())->toBe(0);
});

test('the groups attribute maps the user to a group', function () {
    config(['ada.auth.saml.attributes.groups' => 'groups']);
    updateSettings(AuthSettings::class, ['group_mapping' => true]);
    $staff = Group::factory()->create(['idp_groups' => ['staff@example.edu']]);

    samlSignIn(['groups' => ['all@example.edu', 'staff@example.edu']])->assertRedirect(route('home'));

    expect(User::query()->sole()->group_id)->toBe($staff->id)
        ->and(UserIdentity::query()->sole()->last_claims['groups'])->toBe('all@example.edu, staff@example.edu');
});

test('unsolicited responses restart a normal sign-in', function () {
    postAcs(samlResponse(null))->assertRedirect(route('auth.redirect', 'saml'));
    $this->assertGuest();
});

test('invalid responses are refused', function (array $overrides) {
    $requestId = startSignIn();

    postAcs(samlResponse($requestId, $overrides))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['auth' => __('auth.errors.provider_error')]);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
})->with([
    'unsigned' => [['sign' => false]],
    'tampered after signing' => [['tamper' => 'mallory@example.edu']],
    'signed by another key' => [fn () => (fn (array $other) => ['key' => $other['key'], 'cert' => $other['cert']])(idpKeyPair())],
    'wrong audience' => [['audience' => 'https://evil.example/metadata']],
    'wrong destination' => [['destination' => 'https://evil.example/acs']],
    'wrong issuer' => [['issuer' => 'https://evil.example/idp']],
    'expired' => [['notOnOrAfter' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600)]],
]);

test('the e-mail domain policy still applies', function () {
    $requestId = startSignIn();

    postAcs(samlResponse($requestId, ['email' => 'someone@other.edu']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['auth' => __('auth.errors.domain_not_allowed')]);
});

test('the metadata describes Ada as a service provider', function () {
    $xml = $this->get(route('auth.saml.metadata'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/samlmetadata+xml')
        ->getContent();

    expect($xml)->toContain('entityID="https://ada.example.edu/auth/saml/metadata"')
        ->toContain('Location="https://ada.example.edu/auth/saml/acs"')
        ->toContain('urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress');
});

test('sign-in is not offered until the IdP is configured', function () {
    config(['ada.auth.saml.idp_x509_cert' => null]);

    $this->get(route('home'))->assertInertia(fn ($page) => $page->where('providers', []));
    $this->get(route('auth.redirect', 'saml'))->assertNotFound();
});

test('the login page offers the configured IdP', function () {
    $this->get(route('home'))->assertInertia(fn ($page) => $page->where('providers', [['key' => 'saml', 'label' => 'Google']]));
});

test('the admin sign-in page shows the values to enter in Google Admin', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.authentication.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('saml.acs_url', 'https://ada.example.edu/auth/saml/acs')
            ->where('saml.entity_id', 'https://ada.example.edu/auth/saml/metadata')
            ->where('saml.configured', true)
            ->where('saml.idp_entity_id', IDP_ENTITY)
            ->where('saml.certificate.expires_at', gmdate('Y-m-d', strtotime('+365 days')))
            ->missing('saml.idp_x509_cert'));
});

test('the certificate can be given as a file path', function (string $key) {
    $path = tempnam(sys_get_temp_dir(), 'idp');
    file_put_contents($path, $this->idp['cert']);
    config(['ada.auth.saml.idp_x509_cert' => null, "ada.auth.saml.{$key}" => $path]);

    try {
        $requestId = startSignIn();
        postAcs(samlResponse($requestId))->assertRedirect(route('home'));
        $this->assertAuthenticated();
    } finally {
        unlink($path);
    }
})->with(['in SAML_IDP_CERT_PATH' => 'idp_x509_cert_path', 'in SAML_IDP_CERT' => 'idp_x509_cert']);

test('an unreadable certificate path leaves sign-in unconfigured', function () {
    config(['ada.auth.saml.idp_x509_cert' => '/nonexistent/google.pem']);

    $this->get(route('home'))->assertInertia(fn ($page) => $page->where('providers', []));
});
