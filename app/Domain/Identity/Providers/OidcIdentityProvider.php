<?php

namespace App\Domain\Identity\Providers;

use App\Domain\Identity\Contracts\RedirectIdentityProvider;
use App\Domain\Identity\Data\ExternalIdentity;
use App\Domain\Identity\Exceptions\IdentityRejected;
use App\Domain\Identity\Exceptions\RejectionReason;
use App\Domain\Identity\Oidc\IdTokenVerifier;
use App\Domain\Identity\Oidc\InvalidIdToken;
use App\Domain\Identity\Oidc\OidcDiscovery;
use App\Domain\Identity\Oidc\OidcUnavailable;
use App\Domain\Identity\Oidc\UnknownSigningKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OpenID Connect sign-in (authorization code flow with PKCE), with a preset
 * for Microsoft Entra ID.
 *
 * The browser gets a one-time state, nonce and PKCE verifier kept in the
 * session; the callback must return the state, the ID token must carry the
 * nonce, and the code can only be redeemed with the verifier. The ID token
 * comes straight from the token endpoint over TLS and its signature is still
 * verified against the provider's keys (RS256 or ES256 only), together with
 * issuer, audience, authorized party, expiry and, for Entra, the tenant.
 */
final class OidcIdentityProvider implements RedirectIdentityProvider
{
    public const KEY = 'oidc';

    /** How long a sign-in may take at the identity provider. */
    private const PENDING_TTL_SECONDS = 600;

    /** At most this many sign-ins (browser tabs) wait at once per session. */
    private const MAX_PENDING = 5;

    /** Allowed clock difference with the identity provider. */
    private const LEEWAY_SECONDS = 60;

    private const SESSION_KEY = 'oidc.pending';

    private const ENTRA_ISSUER = '#^https://login\.microsoftonline\.com/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/v2\.0$#';

    /**
     * @param  array<string, mixed>  $config  config('ada.auth.oidc')
     */
    public function __construct(
        private readonly array $config,
        private readonly OidcDiscovery $discovery,
        private readonly IdTokenVerifier $verifier,
        private readonly string $appUrl,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        $label = $this->config['label'] ?? null;

        if (is_string($label) && $label !== '') {
            return $label;
        }

        return $this->isEntra() ? 'Microsoft' : 'SSO';
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false) && $this->configurationProblem() === null;
    }

    /**
     * What is wrong with the .env settings, for ada:doctor and the admin
     * panel; null when sign-in can be offered.
     */
    public function configurationProblem(): ?string
    {
        $issuer = $this->issuer();

        return match (true) {
            $issuer === '' => 'OIDC_ISSUER is not set.',
            ! $this->discovery->acceptableUrl($issuer) => 'OIDC_ISSUER must be an https URL.',
            $this->clientId() === '' => 'OIDC_CLIENT_ID is not set.',
            $this->clientSecret() === '' => 'OIDC_CLIENT_SECRET is not set.',
            ! in_array($this->preset(), ['entra', 'generic'], true) => 'OIDC_PRESET must be entra or generic.',
            $this->isEntra() && $this->tenantId() === null => 'With OIDC_PRESET=entra, OIDC_ISSUER must be https://login.microsoftonline.com/<tenant ID>/v2.0 (a single tenant; not common or organizations).',
            ! in_array('openid', $this->scopes(), true) => 'OIDC_SCOPES must contain openid.',
            default => null,
        };
    }

    public function requiresHostedDomain(): bool
    {
        // The e-mail domain is checked; Entra additionally pins the tenant.
        return false;
    }

    public function redirectUri(): string
    {
        return rtrim($this->appUrl, '/').'/auth/'.self::KEY.'/callback';
    }

    /**
     * What the administrator needs to register Ada at the identity provider,
     * and the values Ada uses. The client secret is never included.
     *
     * @return array{enabled: bool, configured: bool, problem: string|null, preset: string, issuer: string|null, client_id: string|null, redirect_uri: string, scopes: string}
     */
    public function setupDetails(): array
    {
        $clientId = $this->clientId();

        return [
            'enabled' => (bool) ($this->config['enabled'] ?? false),
            'configured' => $this->isEnabled(),
            'problem' => $this->configurationProblem(),
            'preset' => $this->preset(),
            'issuer' => $this->issuer() !== '' ? $this->issuer() : null,
            // The client ID is not secret, but a shortened form is enough to recognize it.
            'client_id' => $clientId !== '' ? Str::mask($clientId, '•', 8) : null,
            'redirect_uri' => $this->redirectUri(),
            'scopes' => implode(' ', $this->scopes()),
        ];
    }

    public function discovery(): OidcDiscovery
    {
        return $this->discovery;
    }

    public function redirect(Request $request): RedirectResponse
    {
        try {
            $endpoint = $this->discovery->configuration()['authorization_endpoint'];
        } catch (OidcUnavailable $exception) {
            Log::error('OIDC discovery failed.', ['error' => $exception->getMessage()]);

            return redirect()->route('login')->withErrors(['auth' => __('auth.errors.provider_error')]);
        }

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);

        $pending = array_filter(
            (array) $request->session()->get(self::SESSION_KEY, []),
            fn (mixed $entry): bool => is_array($entry) && ($entry['expires_at'] ?? 0) > time(),
        );
        $pending[$state] = ['nonce' => $nonce, 'verifier' => $verifier, 'expires_at' => time() + self::PENDING_TTL_SECONDS];
        $request->session()->put(self::SESSION_KEY, array_slice($pending, -self::MAX_PENDING, preserve_keys: true));

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], encoding_type: PHP_QUERY_RFC3986);

        return new RedirectResponse($endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query);
    }

    /**
     * @throws IdentityRejected
     */
    public function resolveCallback(Request $request): ExternalIdentity
    {
        $state = $request->query('state');
        $pending = (array) $request->session()->get(self::SESSION_KEY, []);
        $entry = is_string($state) ? ($pending[$state] ?? null) : null;

        // Single use, whatever the outcome.
        if (is_string($state)) {
            unset($pending[$state]);
            $request->session()->put(self::SESSION_KEY, $pending);
        }

        if (! is_array($entry) || ($entry['expires_at'] ?? 0) <= time()) {
            throw new IdentityRejected(RejectionReason::InvalidState);
        }

        $error = $request->query('error');

        if (is_string($error)) {
            // e.g. access_denied when the user is not assigned to the app.
            Log::warning('OIDC sign-in returned an error.', ['error' => Str::limit($error, 64)]);

            throw new IdentityRejected(RejectionReason::ProviderError);
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            throw new IdentityRejected(RejectionReason::ProviderError);
        }

        try {
            $claims = $this->idTokenClaims($this->redeem($code, (string) $entry['verifier']), (string) $entry['nonce']);
        } catch (InvalidIdToken $exception) {
            Log::warning('OIDC ID token rejected.', ['check' => $exception->getMessage()]);

            throw new IdentityRejected(RejectionReason::ProviderError, $exception);
        } catch (OidcUnavailable $exception) {
            Log::error('OIDC sign-in failed.', ['error' => $exception->getMessage()]);

            throw new IdentityRejected(RejectionReason::ProviderError, $exception);
        }

        return $this->toExternalIdentity($claims);
    }

    /**
     * Exchanges the code for tokens and returns the ID token.
     */
    private function redeem(string $code, string $verifier): string
    {
        $configuration = $this->discovery->configuration();
        $methods = $configuration['token_endpoint_auth_methods_supported'];
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $verifier,
            'client_id' => $this->clientId(),
        ];

        // client_secret_basic is the default (RFC 8414 §2); some providers
        // (Okta) only accept the method registered for the client.
        $request = Http::timeout(10)->acceptJson()->asForm()->withoutRedirecting();

        if ($methods === [] || in_array('client_secret_basic', $methods, true)) {
            $request = $request->withBasicAuth(urlencode($this->clientId()), urlencode($this->clientSecret()));
        } else {
            $form['client_secret'] = $this->clientSecret();
        }

        try {
            $response = $request->post($configuration['token_endpoint'], $form);
        } catch (ConnectionException $exception) {
            throw new OidcUnavailable('The token endpoint cannot be reached.', previous: $exception);
        }

        if (! $response->successful()) {
            // The error code (e.g. invalid_client for a wrong or expired
            // secret) helps the administrator; the body is not logged.
            $error = $response->json('error');

            throw new OidcUnavailable("The token endpoint answered HTTP {$response->status()}".(is_string($error) ? ' ('.Str::limit($error, 64).').' : '.'));
        }

        $idToken = $response->json('id_token');

        if (! is_string($idToken) || $idToken === '') {
            throw new InvalidIdToken('id_token');
        }

        return $idToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function idTokenClaims(string $idToken, string $nonce): array
    {
        try {
            $claims = $this->verifier->verify($idToken, $this->discovery->keys());
        } catch (UnknownSigningKey) {
            $claims = $this->verifier->verify($idToken, $this->discovery->keys(fresh: true));
        }

        $now = time();
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        $authorizedParty = $claims['azp'] ?? null;

        match (true) {
            ($claims['iss'] ?? null) !== $this->issuer() => throw new InvalidIdToken('iss'),
            ! in_array($this->clientId(), $audiences, true) => throw new InvalidIdToken('aud'),
            (count($audiences) > 1 || $authorizedParty !== null) && $authorizedParty !== $this->clientId() => throw new InvalidIdToken('azp'),
            ! is_int($claims['exp'] ?? null) || $claims['exp'] < $now - self::LEEWAY_SECONDS => throw new InvalidIdToken('exp'),
            isset($claims['nbf']) && (! is_int($claims['nbf']) || $claims['nbf'] > $now + self::LEEWAY_SECONDS) => throw new InvalidIdToken('nbf'),
            ! is_int($claims['iat'] ?? null) || $claims['iat'] > $now + self::LEEWAY_SECONDS => throw new InvalidIdToken('iat'),
            ! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce']) => throw new InvalidIdToken('nonce'),
            ! is_string($claims['sub'] ?? null) || $claims['sub'] === '' => throw new InvalidIdToken('sub'),
            $this->isEntra() && ($claims['tid'] ?? null) !== $this->tenantId() => throw new InvalidIdToken('tid'),
            default => null,
        };

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function toExternalIdentity(array $claims): ExternalIdentity
    {
        [$email, $verified] = $this->isEntra() ? $this->entraEmail($claims) : [
            $claims['email'] ?? null,
            ($claims['email_verified'] ?? false) === true,
        ];

        $email = is_string($email) ? mb_strtolower(trim($email)) : '';

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Log::warning('OIDC ID token has no usable e-mail address.', ['preset' => $this->preset()]);

            throw new IdentityRejected(RejectionReason::ProviderError);
        }

        $name = $claims['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            $name = trim(((string) ($claims['given_name'] ?? '')).' '.((string) ($claims['family_name'] ?? '')));
        }

        return new ExternalIdentity(
            provider: self::KEY,
            subject: (string) $claims['sub'],
            email: $email,
            emailVerified: $verified,
            hostedDomain: null,
            name: Str::limit(trim($name) !== '' ? trim($name) : Str::before($email, '@'), 255, ''),
            avatarUrl: null,
            safeClaims: array_filter([
                'iss' => $this->issuer(),
                'tid' => is_string($claims['tid'] ?? null) ? $claims['tid'] : null,
                'email_verified' => $verified,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * Entra ID has no email_verified claim, and its "email" attribute can be
     * set to any address by whoever administers the user (or the user
     * themself, for some account types). It is trusted only with the
     * optional claim xms_edov ("email domain owner verified"). Otherwise the
     * sign-in name is used: for members of the (pinned) tenant it is the user
     * principal name, whose domain must be verified in the tenant. Guest
     * accounts are refused.
     *
     * @param  array<string, mixed>  $claims
     * @return array{0: mixed, 1: bool}
     */
    private function entraEmail(array $claims): array
    {
        $verifiedDomain = in_array($claims['xms_edov'] ?? null, [true, 1, '1', 'true'], true);

        if (is_string($claims['email'] ?? null) && $verifiedDomain) {
            return [$claims['email'], true];
        }

        $username = $claims['preferred_username'] ?? null;
        $identityProvider = $claims['idp'] ?? null;

        // Guest accounts come from other directories: their token names the
        // home directory in "idp", and their sign-in name proves nothing here.
        $guest = is_string($identityProvider) && ! str_contains($identityProvider, (string) $this->tenantId());

        if ($guest || ! is_string($username) || str_contains($username, '#')) {
            return [null, false];
        }

        return [$username, true];
    }

    private function isEntra(): bool
    {
        return $this->preset() === 'entra';
    }

    private function tenantId(): ?string
    {
        return preg_match(self::ENTRA_ISSUER, $this->issuer(), $matches) === 1 ? $matches[1] : null;
    }

    private function preset(): string
    {
        return strtolower(trim((string) ($this->config['preset'] ?? 'generic')));
    }

    private function issuer(): string
    {
        return trim((string) ($this->config['issuer'] ?? ''));
    }

    private function clientId(): string
    {
        return trim((string) ($this->config['client_id'] ?? ''));
    }

    private function clientSecret(): string
    {
        return (string) ($this->config['client_secret'] ?? '');
    }

    /**
     * @return list<string>
     */
    private function scopes(): array
    {
        return array_values(array_filter(explode(' ', (string) ($this->config['scopes'] ?? 'openid email profile'))));
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
