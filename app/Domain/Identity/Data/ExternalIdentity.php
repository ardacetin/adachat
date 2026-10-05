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
    ) {}

    /**
     * Lower-cased domain part of the e-mail address.
     */
    public function emailDomain(): string
    {
        $at = strrpos($this->email, '@');

        return $at === false ? '' : mb_strtolower(substr($this->email, $at + 1));
    }
}
