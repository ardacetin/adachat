<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * An optional institution-wide monthly spending cap in US dollars (a decimal
 * string, never a float; null = no cap) and the addresses that receive the
 * cap alerts and the monthly report.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('institution.monthly_cap_usd', null);
        $this->migrator->add('institution.notification_emails', []);
    }
};
