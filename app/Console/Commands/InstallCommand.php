<?php

namespace App\Console\Commands;

use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Domain\Institution\Settings\AuthSettings;
use App\Models\Group;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * First-run setup. Idempotent: safe to run again after upgrades.
 * Initial institution and sign-in settings are seeded from .env by the
 * settings migrations (database/settings).
 */
#[Signature('ada:install')]
#[Description('Prepare a new Ada installation and check its configuration')]
class InstallCommand extends Command
{
    public function handle(IdentityProviderRegistry $providers, AuthSettings $authSettings): int
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

        if ($authSettings->allowed_domains === []) {
            $problems[] = 'No allowed sign-in domain is configured (admin panel or AUTH_ALLOWED_DOMAINS): nobody can sign in.';
        }

        if (! file_exists(public_path('storage'))) {
            $problems[] = 'Uploaded logos are not publicly reachable: run php artisan storage:link.';
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
