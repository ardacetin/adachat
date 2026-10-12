<?php

namespace App\Console\Commands;

use App\Support\FrontendBuild;
use App\Support\InstallState;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The server-side steps of an upgrade without Docker, once the new
 * version is checked out and its dependencies are installed
 * (docs/deployment.md §8). Stops before touching anything when the PHP
 * packages or the front end do not belong to the checked-out version.
 */
#[Signature('ada:upgrade {--no-maintenance : Do not put the application in maintenance mode while migrating}')]
#[Description('Finish an upgrade: check the build, migrate, refresh the caches, run the doctor')]
class UpgradeCommand extends Command
{
    public function handle(): int
    {
        $outdated = InstallState::outdatedPackages();

        if ($outdated === null || $outdated !== []) {
            $this->components->error('The PHP packages do not match composer.lock. Run first: composer install --no-dev --optimize-autoloader');

            return self::FAILURE;
        }

        if (FrontendBuild::status() !== 'current') {
            $this->components->error('The front end was not built from this version. Run first: npm ci && npm run build');

            return self::FAILURE;
        }

        $maintenance = ! $this->option('no-maintenance') && ! $this->laravel->isDownForMaintenance();

        if ($maintenance) {
            // Answers already streaming finish; new requests wait.
            $this->call('down', ['--retry' => 60]);
        }

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->components->error('A migration failed. The application stays in maintenance mode: fix the cause, run php artisan migrate --force, then php artisan up.');

            return self::FAILURE;
        }

        $this->call('optimize:clear');
        $this->call('optimize');

        if ($maintenance) {
            $this->call('up');
        }

        $this->components->warn('Reload PHP-FPM (e.g. sudo systemctl reload php8.4-fpm): OPcache keeps serving the old code until then.');

        return $this->call('ada:doctor');
    }
}
