<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Data\ExternalIdentity;
use App\Domain\Identity\Exceptions\IdentityRejected;
use App\Domain\Identity\Exceptions\RejectionReason;

/**
 * Institution sign-in policy: only verified addresses, from allowed domains
 * or added by an administrator (invited). Never trusts the e-mail string
 * alone.
 */
final class AllowedDomainPolicy
{
    /**
     * @param  list<string>  $allowedDomains  Lower-cased domains.
     */
    public function __construct(private readonly array $allowedDomains) {}

    /**
     * @throws IdentityRejected
     */
    public function assertAllowed(ExternalIdentity $identity, bool $requireHostedDomain, bool $invited = false): void
    {
        if (! $identity->emailVerified) {
            throw new IdentityRejected(RejectionReason::EmailNotVerified);
        }

        // An administrator added exactly this address (Admin > Users).
        if ($invited) {
            return;
        }

        if ($this->allowedDomains === [] || ! $this->isAllowed($identity->emailDomain())) {
            throw new IdentityRejected(RejectionReason::DomainNotAllowed);
        }

        if ($requireHostedDomain && ! $this->isAllowed(mb_strtolower((string) $identity->hostedDomain))) {
            throw new IdentityRejected(RejectionReason::DomainNotAllowed);
        }
    }

    private function isAllowed(string $domain): bool
    {
        return $domain !== '' && in_array($domain, $this->allowedDomains, true);
    }
}
