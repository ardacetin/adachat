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
