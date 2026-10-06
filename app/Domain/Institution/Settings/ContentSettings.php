<?php

namespace App\Domain\Institution\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Texts administrators may reword: the landing page and the invitation
 * e-mail. Each holds locale → field → text; a field that is missing or
 * empty keeps Ada's default from the lang files (ContentTexts).
 */
class ContentSettings extends Settings
{
    /** @phpstan-var array<string, array<string, string>> */
    public array $landing;

    /** @phpstan-var array<string, array<string, string>> */
    public array $invitation_email;

    public static function group(): string
    {
        return 'content';
    }
}
