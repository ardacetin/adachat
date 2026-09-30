<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Initial institution settings, taken from .env (config/ada.php) once.
 * Afterwards the admin panel is the source of truth.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('institution', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('name', config('ada.institution.name') ?: config('app.name'));
            $blueprint->add('short_name', config('ada.institution.short_name'));
            $blueprint->add('domain', config('ada.institution.domain'));
            $blueprint->add('support_email', null);
            $blueprint->add('default_locale', config('ada.locales.default'));
            $blueprint->add('timezone', config('ada.institution.timezone'));
            $blueprint->add('privacy_url', null);
            $blueprint->add('terms_url', null);
            $blueprint->add('primary_color', null);
            $blueprint->add('logo_path', null);
            $blueprint->add('logo_dark_path', null);
            $blueprint->add('favicon_path', null);
        });
    }
};
