<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * How users see their own budget: "amount" (US dollars and a percentage) or
 * "percent" (only the share used; no dollar amounts are sent to the browser).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('institution.budget_display', 'amount');
    }
};
