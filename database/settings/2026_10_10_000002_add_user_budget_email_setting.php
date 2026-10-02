<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Whether users get an e-mail at 80 % and 100 % of their monthly budget
 * (each user can still turn it off for themselves).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('institution.user_budget_emails', true);
    }
};
