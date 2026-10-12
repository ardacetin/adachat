<?php

namespace App\Support;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * What an upgrade can leave behind: migrations that have not run, and
 * Composer packages that differ from composer.lock.
 */
final class InstallState
{
    /**
     * Names of migrations (database and settings) that have not run; null
     * when the migration table cannot be read.
     *
     * @return list<string>|null
     */
    public static function pendingMigrations(): ?array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        try {
            if (! $migrator->repositoryExists()) {
                return null;
            }

            $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);

            return array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Packages composer.lock requires that are missing or at another
     * version in vendor/; null when either file cannot be read.
     *
     * @return list<string>|null
     */
    public static function outdatedPackages(?string $base = null): ?array
    {
        $base ??= base_path();
        $lock = json_decode((string) @file_get_contents("{$base}/composer.lock"), true);
        $installed = json_decode((string) @file_get_contents("{$base}/vendor/composer/installed.json"), true);

        if (! is_array($lock) || ! is_array($installed)) {
            return null;
        }

        $versions = [];

        foreach ($installed['packages'] ?? $installed as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $versions[$package['name']] = $package['version'] ?? null;
            }
        }

        $outdated = [];

        foreach ($lock['packages'] ?? [] as $package) {
            $name = $package['name'] ?? null;

            if (is_string($name) && ($versions[$name] ?? null) !== ($package['version'] ?? null)) {
                $outdated[] = $name;
            }
        }

        return $outdated;
    }
}
