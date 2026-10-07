<?php

namespace App\Domain\Identity\Data;

/**
 * A verified identity asserted by an external identity provider, after the
 * provider adapter has validated the protocol (state, tokens, signatures).
 */
final readonly class ExternalIdentity
{
    /**
     * @param  array<string, scalar|null>  $safeClaims  Non-secret claims kept for troubleshooting.
     * @param  string|null  $previousSubject  The subject this identity was stored under before (e.g. its e-mail before a stable ID was configured); adopted on first sign-in.
     * @param  list<string>|null  $groups  The person's groups at the identity provider; null when the provider is not set up to send them, or did not (Entra ID with too many groups).
     */
    public function __construct(
        public string $provider,
        public string $subject,
        public string $email,
        public bool $emailVerified,
        public ?string $hostedDomain,
        public string $name,
        public ?string $avatarUrl,
        public array $safeClaims = [],
        public ?string $previousSubject = null,
        public ?array $groups = null,
    ) {}

    /**
     * Group values from a claim or attribute: trimmed, distinct, non-empty
     * strings, at most 200 of them.
     *
     * @return list<string>
     */
    public static function groupValues(mixed $values): array
    {
        $values = is_array($values) ? $values : (is_string($values) ? [$values] : []);
        $groups = [];

        foreach ($values as $value) {
            if (is_string($value) || is_int($value)) {
                $value = trim((string) $value);

                if ($value !== '' && mb_strlen($value) <= 255) {
                    $groups[mb_strtolower($value)] ??= $value;
                }
            }
        }

        return array_slice(array_values($groups), 0, 200);
    }

    /**
     * Lower-cased domain part of the e-mail address.
     */
    public function emailDomain(): string
    {
        $at = strrpos($this->email, '@');

        return $at === false ? '' : mb_strtolower(substr($this->email, $at + 1));
    }
}
