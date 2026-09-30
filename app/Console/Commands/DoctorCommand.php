<?php

namespace App\Console\Commands;

use App\Domain\Identity\Providers\SamlIdentityProvider;
use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Domain\Institution\Settings\AuthSettings;
use App\Models\Group;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Checks a running installation for the problems that break it in
 * production. Exits with an error when anything fails, so it can run in a
 * deployment pipeline or a monitoring job.
 */
#[Signature('ada:doctor')]
#[Description('Check the configuration and health of this Ada installation')]
class DoctorCommand extends Command
{
    private int $failures = 0;

    private int $warnings = 0;

    public function handle(IdentityProviderRegistry $providers, AuthSettings $auth, SamlIdentityProvider $saml): int
    {
        $production = app()->environment('production');

        $this->components->info('Application');
        $this->check('APP_KEY is set', filled(config('app.key')));
        $this->check('APP_ENV is production', $production, warnOnly: true, hint: 'Use APP_ENV=production on servers.');
        $this->check('APP_DEBUG is off', ! config('app.debug') || ! $production, hint: 'APP_DEBUG=true shows stack traces and settings to anyone.');
        $this->check('APP_URL uses HTTPS', str_starts_with((string) config('app.url'), 'https://') || ! $production, hint: 'SAML and secure cookies need https.');
        $this->check('Development login is off', ! config('ada.auth.dev_login') || ! $production, hint: 'Set ADA_DEV_LOGIN=false.');
        $this->check('Secure session cookies', (bool) config('session.secure') || ! $production, warnOnly: true, hint: 'Set SESSION_SECURE_COOKIE=true behind https.');

        $this->components->info('Database and storage');
        $version = $this->databaseVersion();
        $this->check('Database connection', $version !== null, hint: 'Check the DB_* settings.');

        if ($version !== null) {
            $this->check("MySQL 8.4 or later ({$version})", version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?: '0', '8.4', '>='), warnOnly: true, hint: 'MySQL 8.4 LTS is the supported (and tested) database.');
        }

        $this->check('Storage is writable', is_writable(storage_path('framework')) && is_writable(storage_path('logs')), hint: 'The web server user must be able to write to storage/.');
        $this->check('Public storage link', file_exists(public_path('storage')), warnOnly: true, hint: 'Run php artisan storage:link (logos).');
        $this->check('Shared cache', config('cache.default') !== 'array', hint: 'SAML sign-in and the Stop button need CACHE_STORE=database or redis.');

        $this->components->info('Scheduler');
        $heartbeat = Cache::get('ada:scheduler:heartbeat');
        $recent = is_string($heartbeat) && CarbonImmutable::parse($heartbeat)->greaterThan(now()->subMinutes(5));
        $this->check('Scheduler ran in the last 5 minutes', $recent, hint: 'Add a cron entry: * * * * * php artisan schedule:run');

        $this->components->info('Sign-in');
        $this->check('An identity provider is configured', $providers->enabledKeys() !== [], hint: 'Set SAML_IDP_* (docs/authentication.md).');
        $this->check('Allowed e-mail domains are set', $auth->allowed_domains !== [] && ! in_array('example.edu', $auth->allowed_domains, true), hint: 'Admin > Sign-in, or php artisan ada:install.');

        $certificate = $saml->setupDetails()['certificate'];

        if ($certificate !== null && $certificate['expires_at'] !== null) {
            $expires = CarbonImmutable::parse($certificate['expires_at']);
            $this->check("SAML certificate valid until {$expires->toDateString()}", $expires->greaterThan(now()->addDays(30)), warnOnly: $expires->isFuture(), hint: 'Renew the certificate in Google Admin and update SAML_IDP_CERT.');
        }

        $this->components->info('AI');
        $this->check('An enabled provider has an API key', Provider::query()->where('enabled', true)->whereHas('activeCredential')->exists(), hint: 'Admin > Providers.');
        $this->check('The default group can use a model', Group::default()->modelAliases()->where('enabled', true)->exists(), warnOnly: true, hint: 'Admin > Model aliases: assign an alias to the Default group.');

        $this->newLine();

        if ($this->failures > 0) {
            $this->components->error("{$this->failures} problem(s) and {$this->warnings} warning(s).");

            return self::FAILURE;
        }

        $this->warnings > 0
            ? $this->components->warn("No problems; {$this->warnings} warning(s).")
            : $this->components->info('Everything looks good.');

        return self::SUCCESS;
    }

    private function check(string $label, bool $ok, bool $warnOnly = false, ?string $hint = null): void
    {
        $status = $ok ? '<fg=green>OK</>' : ($warnOnly ? '<fg=yellow>WARN</>' : '<fg=red>FAIL</>');
        $this->components->twoColumnDetail($label, $status);

        if (! $ok) {
            $warnOnly ? $this->warnings++ : $this->failures++;

            if ($hint !== null) {
                $this->line("    <fg=gray>{$hint}</>");
            }
        }
    }

    private function databaseVersion(): ?string
    {
        try {
            $version = DB::selectOne('SELECT VERSION() AS version');

            return is_object($version) && isset($version->version) ? (string) $version->version : null;
        } catch (Throwable) {
            return null;
        }
    }
}
