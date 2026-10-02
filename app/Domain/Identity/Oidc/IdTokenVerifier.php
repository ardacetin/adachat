<?php

namespace App\Domain\Identity\Oidc;

use JsonException;

/**
 * Verifies the signature of an OpenID Connect ID token (a signed JWT, JWS
 * compact form) against the identity provider's JSON Web Key Set, with PHP's
 * OpenSSL extension.
 *
 * Only asymmetric algorithms are accepted: RS256 (Microsoft Entra ID, Google,
 * Keycloak, Okta) and ES256. "none", HMAC (HS*, which would let anyone who
 * knows the client secret sign tokens) and every other value are refused
 * before any key is touched. The claims are checked by the caller.
 */
final class IdTokenVerifier
{
    private const ALGORITHMS = ['RS256', 'ES256'];

    /**
     * @param  array<string, mixed>  $jwks  The parsed JWKS document ({"keys": [...]}).
     * @return array<string, mixed> The token's claims.
     *
     * @throws InvalidIdToken
     * @throws UnknownSigningKey when no key in the set matches the token's kid
     */
    public function verify(string $token, array $jwks): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new InvalidIdToken('malformed');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = $this->json($encodedHeader);
        $algorithm = $header['alg'] ?? null;

        if (! in_array($algorithm, self::ALGORITHMS, true)) {
            throw new InvalidIdToken('algorithm');
        }

        if (isset($header['crit'])) {
            // No extensions are understood, so none may be required.
            throw new InvalidIdToken('crit');
        }

        $key = $this->publicKey($this->findKey($jwks, $header, $algorithm), $algorithm);
        $signature = $this->decode($encodedSignature);

        if ($algorithm === 'ES256') {
            $signature = $this->ecdsaToDer($signature);
        }

        if (openssl_verify("{$encodedHeader}.{$encodedPayload}", $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidIdToken('signature');
        }

        return $this->json($encodedPayload);
    }

    /**
     * @param  array<string, mixed>  $jwks
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>
     */
    private function findKey(array $jwks, array $header, string $algorithm): array
    {
        $kty = $algorithm === 'RS256' ? 'RSA' : 'EC';
        $kid = $header['kid'] ?? null;

        $candidates = array_values(array_filter(
            is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [],
            fn (mixed $key): bool => is_array($key)
                && ($key['kty'] ?? null) === $kty
                && ($key['use'] ?? 'sig') === 'sig'
                && ($key['alg'] ?? $algorithm) === $algorithm
                && (! is_string($kid) || ($key['kid'] ?? null) === $kid),
        ));

        // Without a kid the key must be unambiguous.
        if (count($candidates) !== 1) {
            throw new UnknownSigningKey;
        }

        return $candidates[0];
    }

    /**
     * @param  array<string, mixed>  $jwk
     */
    private function publicKey(array $jwk, string $algorithm): \OpenSSLAsymmetricKey
    {
        $key = $algorithm === 'RS256'
            ? openssl_pkey_get_public($this->rsaPem($jwk))
            : openssl_pkey_new(['ec' => [
                'curve_name' => ($jwk['crv'] ?? null) === 'P-256' ? 'prime256v1' : throw new InvalidIdToken('curve'),
                'x' => $this->decode((string) ($jwk['x'] ?? '')),
                'y' => $this->decode((string) ($jwk['y'] ?? '')),
            ]]);

        if ($key === false) {
            throw new InvalidIdToken('key');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ($algorithm === 'RS256' && ($details['bits'] ?? 0) < 2048)) {
            throw new InvalidIdToken('key');
        }

        return $key;
    }

    /**
     * PEM SubjectPublicKeyInfo for an RSA JWK (OpenSSL cannot build a public
     * RSA key from n and e directly).
     *
     * @param  array<string, mixed>  $jwk
     */
    private function rsaPem(array $jwk): string
    {
        $modulus = $this->decode((string) ($jwk['n'] ?? ''));
        $exponent = $this->decode((string) ($jwk['e'] ?? ''));

        if ($modulus === '' || $exponent === '') {
            throw new InvalidIdToken('key');
        }

        $rsaPublicKey = $this->der(0x30, $this->derInteger($modulus).$this->derInteger($exponent));
        // AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1), NULL parameters.
        $algorithm = $this->der(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $spki = $this->der(0x30, $algorithm.$this->der(0x03, "\x00".$rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /**
     * JWS carries ECDSA signatures as r || s; OpenSSL expects DER.
     */
    private function ecdsaToDer(string $signature): string
    {
        if (strlen($signature) !== 64) {
            throw new InvalidIdToken('signature');
        }

        return $this->der(0x30, $this->derInteger(substr($signature, 0, 32)).$this->derInteger(substr($signature, 32)));
    }

    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        // A leading 1 bit would make the integer negative.
        if ($bytes === '' || ord($bytes[0]) > 0x7F) {
            $bytes = "\x00".$bytes;
        }

        return $this->der(0x02, $bytes);
    }

    private function der(int $tag, string $content): string
    {
        $length = strlen($content);

        if ($length < 0x80) {
            return chr($tag).chr($length).$content;
        }

        $encoded = ltrim(pack('N', $length), "\x00");

        return chr($tag).chr(0x80 | strlen($encoded)).$encoded.$content;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $segment): array
    {
        try {
            $value = json_decode($this->decode($segment), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidIdToken('malformed');
        }

        if (! is_array($value)) {
            throw new InvalidIdToken('malformed');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false || preg_match('/[^A-Za-z0-9_-]/', $value) === 1) {
            throw new InvalidIdToken('malformed');
        }

        return $decoded;
    }
}
