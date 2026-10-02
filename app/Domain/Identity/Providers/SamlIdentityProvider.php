<?php

namespace App\Domain\Identity\Providers;

use App\Domain\Identity\Contracts\RedirectIdentityProvider;
use App\Domain\Identity\Data\ExternalIdentity;
use App\Domain\Identity\Exceptions\IdentityRejected;
use App\Domain\Identity\Exceptions\RejectionReason;
use App\Domain\Identity\Exceptions\SignInMustRestart;
use DOMDocument;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Error;
use OneLogin\Saml2\Metadata;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use OneLogin\Saml2\ValidationError;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * SAML 2.0 sign-in (SP-initiated, HTTP-Redirect → HTTP-POST), in V1 against
 * a custom SAML app in the Google Workspace admin console.
 *
 * onelogin/php-saml validates the signature (response or assertion), issuer,
 * audience, destination, recipient and validity window. On top of that,
 * every AuthnRequest ID is kept in the cache for a few minutes and accepted
 * once, so a response can only answer a request this Ada instance made and
 * cannot be replayed. The request is also bound to the browser that started
 * it: a random value goes into a short-lived cookie scoped to the ACS path,
 * its hash next to the request ID, and the response is accepted only from a
 * browser that sends the value back. Without it, someone could start a
 * sign-in and have another browser complete it (login CSRF, session
 * swapping). The session is not used for this: browsers do not send the
 * (SameSite=Lax) session cookie with the IdP's cross-site POST.
 */
final class SamlIdentityProvider implements RedirectIdentityProvider
{
    public const KEY = 'saml';

    /** How long an AuthnRequest may be answered. */
    private const REQUEST_TTL_SECONDS = 600;

    /** The cookie that binds a request to the browser that started it. */
    public const BINDING_COOKIE = 'ada_saml_binding';

    /**
     * @param  array<string, mixed>  $config  config('ada.auth.saml')
     */
    public function __construct(
        private readonly array $config,
        private readonly Cache $cache,
        private readonly string $appUrl,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        $label = $this->config['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : 'SAML';
    }

    public function isEnabled(): bool
    {
        return filled($this->config['idp_entity_id'] ?? null)
            && filled($this->config['idp_sso_url'] ?? null)
            && $this->certificate() !== '';
    }

    public function requiresHostedDomain(): bool
    {
        // SAML carries no "hd" claim; the IdP only asserts accounts of its own
        // organisation, and the e-mail domain is still checked.
        return false;
    }

    public function entityId(): string
    {
        return $this->url('/auth/saml/metadata');
    }

    public function acsUrl(): string
    {
        return $this->url('/auth/saml/acs');
    }

    /**
     * What the administrator needs to set up the IdP, and the (public) IdP
     * values Ada is using. Nothing here is secret.
     *
     * @return array{acs_url: string, entity_id: string, metadata_url: string, name_id_format: string, configured: bool, idp_entity_id: string|null, idp_sso_url: string|null, certificate: array{fingerprint: string, expires_at: string|null}|null}
     */
    public function setupDetails(): array
    {
        return [
            'acs_url' => $this->acsUrl(),
            'entity_id' => $this->entityId(),
            'metadata_url' => $this->entityId(),
            'name_id_format' => 'EMAIL',
            'configured' => $this->isEnabled(),
            'idp_entity_id' => is_string($this->config['idp_entity_id'] ?? null) ? $this->config['idp_entity_id'] : null,
            'idp_sso_url' => is_string($this->config['idp_sso_url'] ?? null) ? $this->config['idp_sso_url'] : null,
            'certificate' => $this->certificateDetails(),
        ];
    }

    public function redirect(Request $request): RedirectResponse
    {
        $auth = new Auth($this->settings());
        $url = $auth->login(stay: true);

        // One value per browser, so sign-ins started in two tabs both complete.
        $binding = $request->cookie(self::BINDING_COOKIE);

        if (! is_string($binding) || preg_match('/^[A-Za-z0-9]{40}$/', $binding) !== 1) {
            $binding = Str::random(40);
        }

        $this->cache->put($this->requestKey((string) $auth->getLastRequestID()), hash('sha256', $binding), self::REQUEST_TTL_SECONDS);

        // SameSite=None: the IdP returns the browser with a cross-site POST.
        $cookie = Cookie::create(self::BINDING_COOKIE, $binding)
            ->withExpires(time() + self::REQUEST_TTL_SECONDS)
            ->withPath('/auth/saml/acs')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_NONE);

        return (new RedirectResponse((string) $url))->withCookie($cookie);
    }

    /**
     * @throws IdentityRejected
     * @throws SignInMustRestart when the IdP sent an unsolicited response
     */
    public function resolveCallback(Request $request): ExternalIdentity
    {
        $encoded = $request->input('SAMLResponse');

        if (! is_string($encoded) || $encoded === '') {
            throw new IdentityRejected(RejectionReason::ProviderError);
        }

        $requestId = $this->inResponseTo($encoded);

        if ($requestId === null) {
            // IdP-initiated (e.g. the Google apps menu): not trusted as is, but
            // an SP-initiated round trip completes without user interaction.
            throw new SignInMustRestart;
        }

        // Single use, and only in the browser that made the request: a
        // replayed, expired or carried-over response is refused.
        $expected = $this->cache->pull($this->requestKey($requestId));
        $binding = $request->cookie(self::BINDING_COOKIE);

        if (! is_string($expected) || ! is_string($binding) || ! hash_equals($expected, hash('sha256', $binding))) {
            throw new IdentityRejected(RejectionReason::InvalidState);
        }

        $response = $this->validate($encoded, $requestId);

        return $this->toExternalIdentity($response);
    }

    /**
     * Service provider metadata for the IdP (also the SP entity ID URL).
     */
    public function metadata(): string
    {
        $settings = new Settings($this->settings(), spValidationOnly: true);

        return $settings->getSPMetadata();
    }

    private function validate(string $encoded, string $requestId): Response
    {
        // The Destination/Recipient must be Ada's ACS URL. onelogin derives the
        // "current URL" from $_SERVER, which is wrong behind a proxy; validate
        // against the configured ACS URL instead.
        $previousUri = $_SERVER['REQUEST_URI'] ?? null;
        Utils::setBaseURL($this->appUrl);
        $_SERVER['REQUEST_URI'] = (string) parse_url($this->acsUrl(), PHP_URL_PATH);

        try {
            $response = new Response(new Settings($this->settings()), $encoded);

            if (! $response->isValid($requestId)) {
                Log::warning('SAML response rejected.', ['error' => $response->getError()]);

                throw new IdentityRejected(RejectionReason::ProviderError);
            }

            return $response;
        } catch (Error|ValidationError $exception) {
            Log::warning('SAML response rejected.', ['error' => $exception->getMessage()]);

            throw new IdentityRejected(RejectionReason::ProviderError, $exception);
        } finally {
            // An empty value resets onelogin's host/protocol/port/path overrides.
            Utils::setBaseURL('');

            if ($previousUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previousUri;
            }
        }
    }

    private function toExternalIdentity(Response $response): ExternalIdentity
    {
        $email = mb_strtolower(trim($response->getNameId()));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new IdentityRejected(RejectionReason::ProviderError);
        }

        $attributes = $response->getAttributes();
        $name = trim($this->attribute($attributes, 'first_name').' '.$this->attribute($attributes, 'last_name'));

        return new ExternalIdentity(
            provider: self::KEY,
            // Google's NameID is the primary e-mail address.
            subject: $email,
            email: $email,
            // Asserted by the organisation's own IdP over a signed response.
            emailVerified: true,
            hostedDomain: null,
            name: $name !== '' ? $name : Str::before($email, '@'),
            avatarUrl: null,
            safeClaims: [
                'idp' => (string) ($this->config['idp_entity_id'] ?? ''),
                'name_id_format' => $response->getNameIdFormat(),
            ],
        );
    }

    /**
     * InResponseTo of the (not yet validated) response; only used to look up
     * the request, the value is verified by the full validation.
     */
    private function inResponseTo(string $encoded): ?string
    {
        $xml = base64_decode($encoded, true);

        if ($xml === false) {
            throw new IdentityRejected(RejectionReason::ProviderError);
        }

        try {
            // Utils::loadXML refuses DOCTYPE declarations (no XXE).
            $document = Utils::loadXML(new DOMDocument, $xml);
        } catch (Throwable $exception) {
            throw new IdentityRejected(RejectionReason::ProviderError, $exception);
        }

        if ($document === false || $document->documentElement === null) {
            throw new IdentityRejected(RejectionReason::ProviderError);
        }

        $value = $document->documentElement->getAttribute('InResponseTo');

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, list<string>>  $attributes
     */
    private function attribute(array $attributes, string $name): string
    {
        $key = $this->config['attributes'][$name] ?? $name;

        return is_string($key) ? trim($attributes[$key][0] ?? '') : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => $this->entityId(),
                'assertionConsumerService' => [
                    'url' => $this->acsUrl(),
                    'binding' => Constants::BINDING_HTTP_POST,
                ],
                'NameIDFormat' => Constants::NAMEID_EMAIL_ADDRESS,
                'x509cert' => '',
                'privateKey' => '',
            ],
            'idp' => [
                'entityId' => (string) ($this->config['idp_entity_id'] ?? ''),
                'singleSignOnService' => [
                    'url' => (string) ($this->config['idp_sso_url'] ?? ''),
                    'binding' => Constants::BINDING_HTTP_REDIRECT,
                ],
                'x509cert' => $this->certificate(),
            ],
            'security' => [
                'authnRequestsSigned' => false,
                // A signature on the response OR the assertion is required either
                // way (onelogin rejects unsigned responses); Google signs the
                // assertion, or the whole response with "Signed response".
                'wantMessagesSigned' => false,
                'wantAssertionsSigned' => false,
                'wantNameId' => true,
                'requestedAuthnContext' => false,
                'wantXMLValidation' => true,
                'destinationStrictlyMatches' => true,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
            ],
        ];
    }

    private function certificate(): string
    {
        $pem = $this->config['idp_x509_cert'] ?? null;
        $path = $this->config['idp_x509_cert_path'] ?? null;

        // A file path given in SAML_IDP_CERT instead of SAML_IDP_CERT_PATH is accepted too.
        if (is_string($pem) && ! str_contains($pem, 'BEGIN CERTIFICATE') && str_starts_with(trim($pem), '/')) {
            [$pem, $path] = [null, trim($pem)];
        }

        if ((! is_string($pem) || trim($pem) === '') && is_string($path) && $path !== '' && is_readable($path)) {
            $pem = (string) file_get_contents($path);
        }

        if (! is_string($pem)) {
            return '';
        }

        // Accept full PEM, a single line or "\n" escapes from .env.
        return trim((string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\\\\n|\s+/', '', $pem));
    }

    /**
     * @return array{fingerprint: string, expires_at: string|null}|null
     */
    private function certificateDetails(): ?array
    {
        $body = $this->certificate();

        if ($body === '') {
            return null;
        }

        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($body, 64, "\n")."-----END CERTIFICATE-----\n";
        $parsed = @openssl_x509_parse($pem);
        $fingerprint = @openssl_x509_fingerprint($pem, 'sha256');

        if ($parsed === false || $fingerprint === false) {
            return ['fingerprint' => '', 'expires_at' => null];
        }

        $validTo = $parsed['validTo_time_t'] ?? null;

        return [
            'fingerprint' => strtoupper(implode(':', str_split($fingerprint, 2))),
            'expires_at' => is_int($validTo) ? gmdate('Y-m-d', $validTo) : null,
        ];
    }

    private function requestKey(string $id): string
    {
        return 'saml:request:'.hash('sha256', $id);
    }

    private function url(string $path): string
    {
        return rtrim($this->appUrl, '/').$path;
    }
}
