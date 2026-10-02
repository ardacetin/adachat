<?php

namespace App\Domain\Identity\Oidc;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The identity provider's OpenID Provider Metadata
 * ({issuer}/.well-known/openid-configuration) and signing keys (JWKS), each
 * cached for an hour. The keys are fetched again when a token names a key
 * the cached set does not have (key rotation).
 */
final class OidcDiscovery
{
    private const TTL_SECONDS = 3600;

    private const TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly string $issuer,
        private readonly bool $allowHttp,
        private readonly Cache $cache,
    ) {}

    /**
     * @return array{authorization_endpoint: string, token_endpoint: string, jwks_uri: string, token_endpoint_auth_methods_supported: list<string>}
     *
     * @throws OidcUnavailable
     */
    public function configuration(bool $fresh = false): array
    {
        $key = 'ada:oidc:configuration:'.hash('sha256', $this->issuer);

        if (! $fresh && is_array($cached = $this->cache->get($key))) {
            /** @var array{authorization_endpoint: string, token_endpoint: string, jwks_uri: string, token_endpoint_auth_methods_supported: list<string>} $cached */
            return $cached;
        }

        $document = $this->fetch(rtrim($this->issuer, '/').'/.well-known/openid-configuration')->json();

        if (! is_array($document)) {
            throw new OidcUnavailable('The discovery document is not JSON.');
        }

        // The metadata must be about the configured issuer (OIDC Discovery §4.3).
        if (($document['issuer'] ?? null) !== $this->issuer) {
            throw new OidcUnavailable('The discovery document names a different issuer: check OIDC_ISSUER (exactly as in the document, including a trailing slash).');
        }

        $configuration = [
            'authorization_endpoint' => $this->endpoint($document, 'authorization_endpoint'),
            'token_endpoint' => $this->endpoint($document, 'token_endpoint'),
            'jwks_uri' => $this->endpoint($document, 'jwks_uri'),
            'token_endpoint_auth_methods_supported' => array_values(array_filter(
                (array) ($document['token_endpoint_auth_methods_supported'] ?? []),
                'is_string',
            )),
        ];

        $this->cache->put($key, $configuration, self::TTL_SECONDS);

        return $configuration;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OidcUnavailable
     */
    public function keys(bool $fresh = false): array
    {
        $uri = $this->configuration()['jwks_uri'];
        $key = 'ada:oidc:jwks:'.hash('sha256', $uri);

        if (! $fresh && is_array($cached = $this->cache->get($key))) {
            /** @var array<string, mixed> $cached */
            return $cached;
        }

        $keys = $this->fetch($uri)->json();

        if (! is_array($keys) || ! is_array($keys['keys'] ?? null)) {
            throw new OidcUnavailable('The key set (jwks_uri) is not a JSON Web Key Set.');
        }

        $this->cache->put($key, $keys, self::TTL_SECONDS);

        /** @var array<string, mixed> $keys */
        return $keys;
    }

    /**
     * Fetches the metadata and the keys without the cache. Returns the
     * difference between the identity provider's clock and ours in seconds,
     * when its response carries a Date header.
     *
     * @throws OidcUnavailable
     */
    public function test(): ?int
    {
        $response = $this->fetch(rtrim($this->issuer, '/').'/.well-known/openid-configuration');
        $this->configuration(fresh: true);
        $this->keys(fresh: true);

        $date = $response->header('Date');
        $time = $date !== '' ? strtotime($date) : false;

        return $time === false ? null : $time - time();
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function endpoint(array $document, string $name): string
    {
        $url = $document[$name] ?? null;

        if (! is_string($url) || ! $this->acceptableUrl($url)) {
            throw new OidcUnavailable("The discovery document has no valid {$name}.");
        }

        return $url;
    }

    public function acceptableUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && ($scheme === 'https' || ($this->allowHttp && $scheme === 'http'));
    }

    private function fetch(string $url): Response
    {
        if (! $this->acceptableUrl($url)) {
            throw new OidcUnavailable('The identity provider must be reached over https.');
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->acceptJson()->withoutRedirecting()->get($url);
        } catch (ConnectionException $exception) {
            throw new OidcUnavailable('The identity provider cannot be reached: '.$exception->getMessage(), previous: $exception);
        }

        if (! $response->successful()) {
            throw new OidcUnavailable("The identity provider answered HTTP {$response->status()} for {$url}.");
        }

        return $response;
    }
}
