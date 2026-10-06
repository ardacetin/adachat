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

    /** Institution-wide monthly spending cap in USD (decimal string); null = none. */
    public ?string $monthly_cap_usd;

    /**
     * Who receives the cap alerts and the monthly report.
     *
     * @var list<string>
     */
    public array $notification_emails;

    /** Users get an e-mail at 80 % and 100 % of their monthly budget. */
    public bool $user_budget_emails;

    /** Users may share a read-only copy of a conversation with signed-in users. */
    public bool $conversation_sharing;

    /**
     * The model alias new conversations start with, for users whose group may
     * use it; null: the user's last choice, else the first alias.
     */
    public ?int $default_model_alias_id;

    public static function group(): string
    {
        return 'institution';
    }
}
