<?php

namespace App\Domain\Institution\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Institution identity and branding. The database is the source of truth;
 * .env only provides the initial values (database/settings migrations).
 */
class InstitutionSettings extends Settings
{
    public string $name;

    public ?string $short_name;

    public ?string $domain;

    public ?string $support_email;

    public string $default_locale;

    public string $timezone;

    public ?string $privacy_url;

    public ?string $terms_url;

    /** Hex colour (#rrggbb) used to derive theme tokens; null keeps Ada's neutral theme. */
    public ?string $primary_color;

    /** Paths on the public disk. */
    public ?string $logo_path;

    public ?string $logo_dark_path;

    public ?string $favicon_path;

    /** How users see their budget: "amount" (USD and percentage) or "percent" only. */
    public string $budget_display;

    public static function group(): string
    {
        return 'institution';
    }
}
