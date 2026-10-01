<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Bookkeeping for the monthly report e-mail: the last month (YYYY-MM) it was
 * sent for, so that the hourly scheduler sends each month exactly once.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('reports', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('last_monthly_sent', null);
        });
    }
};
