<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('privacy', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('acknowledgment_enabled', true);
            $blueprint->add('acknowledgment_text', null);
            $blueprint->add('acknowledgment_version', 1);
            // Conversations are kept until the institution chooses a period.
            $blueprint->add('conversation_retention_days', null);
            $blueprint->add('deleted_conversation_days', 30);
            $blueprint->add('usage_retention_months', 24);
        });
    }
};
