<?php

namespace App\Domain\Identity\Contracts;

use App\Domain\Identity\Data\ExternalIdentity;
use App\Domain\Identity\Exceptions\IdentityRejected;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirect-based identity protocols (OAuth 2.0 / OpenID Connect, SAML).
 * Credential-based protocols such as LDAP get their own contract later.
 */
interface RedirectIdentityProvider
{
    /**
     * Stable key used in routes and user_identities.provider, e.g. "google".
     */
    public function key(): string;

    /**
     * Name on the sign-in button, e.g. "Google".
     */
    public function label(): string;

    /**
     * Whether the provider is configured and may be offered on the login page.
     */
    public function isEnabled(): bool;

    /**
     * Whether the institution policy requires a verified hosted domain claim
     * (Google Workspace "hd") in addition to the e-mail domain.
     */
    public function requiresHostedDomain(): bool;

    public function redirect(Request $request): RedirectResponse;

    /**
     * Validate the provider callback and return the asserted identity.
     *
     * @throws IdentityRejected
     */
    public function resolveCallback(Request $request): ExternalIdentity;
}
