<?php

namespace App\Domain\Institution\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * The first-sign-in acknowledgment and data retention (docs/security.md §9,
 * database-design.md §7).
 */
class PrivacySettings extends Settings
{
    /** Ask users to acknowledge the usage notice before they can chat. */
    public bool $acknowledgment_enabled;

    /** @var array<string, string>|null Custom notice per locale; null or an empty locale uses the built-in text. */
    public ?array $acknowledgment_text;

    /** Raised to ask everyone again (e.g. after the text changed). */
    public int $acknowledgment_version;

    /** Conversations are deleted this many days after their last message; null keeps them. */
    public ?int $conversation_retention_days;

    /** Conversations the user deleted are removed for good after this many days. */
    public int $deleted_conversation_days;

    /** The usage ledger (costs, tokens; never content) is kept this many months. */
    public int $usage_retention_months;

    public static function group(): string
    {
        return 'privacy';
    }
}
