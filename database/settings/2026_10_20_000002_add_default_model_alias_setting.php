<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The model alias new conversations start with; null keeps the old
 * behaviour (the user's last choice, else the first alias).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('institution.default_model_alias_id', null);
    }
};
