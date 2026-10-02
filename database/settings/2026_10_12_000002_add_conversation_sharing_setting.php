<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Whether users may share a read-only copy of a conversation with other
 * signed-in users. Turning it off stops every existing link.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('institution.conversation_sharing', true);
    }
};
