<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Group mapping from the identity provider, off until an administrator
 * turns it on. Unmatched: keep (the current group), default (the default
 * group) or reject (no sign-in; never for administrators).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('auth.group_mapping', false);
        $this->migrator->add('auth.group_mapping_unmatched', 'keep');
    }
};
