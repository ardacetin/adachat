<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Personal data protection, off for every kind until an administrator
 * turns it on.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('personal_data', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('rules', ['tckn' => 'off', 'iban' => 'off', 'card' => 'off', 'phone' => 'off', 'email' => 'off']);
            $blueprint->add('patterns', []);
        });
    }
};
