<?php

namespace App\Domain\Identity\Exceptions;

/**
 * Why a sign-in was refused. Values map to translation keys
 * (lang/{locale}/auth.php, key errors.<value>) and are safe to show to users.
 */
enum RejectionReason: string
{
    case InvalidState = 'invalid_state';
    case ProviderError = 'provider_error';
    case EmailNotVerified = 'email_not_verified';
    case DomainNotAllowed = 'domain_not_allowed';
    case NotProvisioned = 'not_provisioned';
    case AccountDisabled = 'account_disabled';
    case AccountConflict = 'account_conflict';
}
