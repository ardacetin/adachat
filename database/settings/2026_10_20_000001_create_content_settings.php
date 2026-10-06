<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Editable texts (landing page, invitation e-mail): nothing overridden
 * yet, so Ada's defaults show.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('content', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('landing', []);
            $blueprint->add('invitation_email', []);
        });
    }
};
