<?php

namespace App\Domain\PersonalData;

use Spatie\LaravelSettings\Settings;

/**
 * What happens to personal data in messages before they reach a provider
 * (docs/personal-data.md). Actions: off, warn (the user confirms), mask
 * (placeholders are sent and put back in the answer) or block.
 */
class PersonalDataSettings extends Settings
{
    /** @var array<string, string> Action per built-in kind (Detectors::KINDS). */
    public array $rules;

    /**
     * Institution-defined patterns, each with a name, pattern and action.
     *
     * @phpstan-var list<array{name: string, pattern: string, action: string}>
     */
    public array $patterns;

    public static function group(): string
    {
        return 'personal_data';
    }
}
