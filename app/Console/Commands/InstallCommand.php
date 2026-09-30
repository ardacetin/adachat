<?php

namespace App\Console\Commands;

use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Models\Group;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * First-run setup. Idempotent: safe to run again after upgrades.
 * Institution settings are seeded here from .env from M3 on.
 */
#[Signature('ada:install')]
#[Description('Prepare a new Ada installation and check its configuration')]
class InstallCommand extends Command
{
    public function handle(IdentityProviderRegistry $providers): int
    {
        $this->components->info('Installing Ada Chat.');

        $this->components->task('Default group', function (): bool {
            if (! Group::query()->where('is_default', true)->exists()) {
                $group = new Group(['name' => 'Default']);
                $group->is_default = true;
                $group->save();
            }

            return true;
        });

        $problems = [];

        if (blank(config('app.key'))) {
            $problems[] = 'APP_KEY is not set: run php artisan key:generate.';
        }

        if (config('ada.auth.allowed_domains') === []) {
            $problems[] = 'AUTH_ALLOWED_DOMAINS is empty: nobody can sign in.';
        }

        if ($providers->enabledKeys() === []) {
            $problems[] = 'No identity provider is configured: set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET.';
        }

        if ($problems !== []) {
            $this->components->warn('Configuration needs attention:');
            $this->components->bulletList($problems);
        }

        $this->components->info('Next: grant yourself access with php artisan ada:user:promote you@your-domain --role=super_admin');

        return self::SUCCESS;
    }
}
