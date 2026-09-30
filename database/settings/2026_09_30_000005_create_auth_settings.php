<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Initial sign-in policy, taken from .env (config/ada.php) once.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('auth', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('allowed_domains', config('ada.auth.allowed_domains'));
            $blueprint->add('auto_provision', config('ada.auth.auto_provision'));
        });
    }
};
