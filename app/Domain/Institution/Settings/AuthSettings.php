<?php

namespace App\Domain\Institution\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Sign-in policy. Provider secrets (e.g. Google client secret) stay in .env:
 * they are needed before anyone can sign in to change settings.
 */
class AuthSettings extends Settings
{
    /** @var list<string> Lower-cased e-mail / Workspace domains allowed to sign in. */
    public array $allowed_domains;

    /** Create users on their first successful sign-in. */
    public bool $auto_provision;

    public static function group(): string
    {
        return 'auth';
    }
}
