<?php

namespace App\Console\Commands;

use App\Domain\AI\Catalog\ModelCatalog;
use App\Domain\AI\Enums\ProviderDriver;
use App\Domain\Identity\Oidc\OidcUnavailable;
use App\Domain\Identity\Providers\OidcIdentityProvider;
use App\Domain\Identity\Providers\SamlIdentityProvider;
use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Domain\Institution\Settings\AuthSettings;
use App\Domain\Institution\Settings\InstitutionSettings;
use App\Domain\Institution\Settings\PrivacySettings;
use App\Models\AiModel;
use App\Models\Group;
use App\Models\ModelAlias;
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
        $this->check('Log files rotate', ! $production || $this->logsRotate(), warnOnly: true, hint: 'Set LOG_CHANNEL=json (JSON, a file per day) or stderr in Docker.');
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

        $this->components->info('E-mail');
        $mailer = (string) config('mail.default');
        $notified = app(InstitutionSettings::class)->notification_emails !== [];
        $this->check("Mail is sent ({$mailer})", ! in_array($mailer, ['log', 'array'], true) || ! $notified, warnOnly: true, hint: 'Notification e-mails are set but MAIL_MAILER only writes to the log: set the MAIL_* settings (docs/deployment.md).');

        $this->components->info('Sign-in');
        $this->check('An identity provider is configured', $providers->enabledKeys() !== [], hint: 'Set SAML_IDP_* or OIDC_* (docs/authentication.md).');
        $this->check('Allowed e-mail domains are set', $auth->allowed_domains !== [] && ! in_array('example.edu', $auth->allowed_domains, true), hint: 'Admin > Sign-in, or php artisan ada:install.');

        $this->checkOidc(app(OidcIdentityProvider::class));

        if ($saml->isEnabled()) {
            $this->check('SAML accounts follow a stable ID, not the e-mail address', $saml->subjectAttribute() !== null, warnOnly: true, hint: 'Set SAML_ATTRIBUTE_SUBJECT, or disable the Ada account of everyone who leaves before their address is reused (docs/authentication.md).');
        }

        $certificate = $saml->setupDetails()['certificate'];

        if ($certificate !== null && $certificate['expires_at'] !== null) {
            $expires = CarbonImmutable::parse($certificate['expires_at']);
            $this->check("SAML certificate valid until {$expires->toDateString()}", $expires->greaterThan(now()->addDays(30)), warnOnly: $expires->isFuture(), hint: 'Renew the certificate in Google Admin and update SAML_IDP_CERT.');
        }

        $this->components->info('AI');
        $usable = Provider::query()->where('enabled', true)
            ->where(fn ($query) => $query->whereHas('activeCredential')->orWhere('driver', ProviderDriver::OpenAICompatible->value))
            ->exists();
        $this->check('An enabled provider can be used (API key, or a keyless OpenAI-compatible server)', $usable, hint: 'Admin > Providers.');
        $this->check('The default group can use a model', Group::default()->modelAliases()->where('enabled', true)->exists(), warnOnly: true, hint: 'Admin > Model aliases: assign an alias to the Default group.');

        $catalog = app(ModelCatalog::class);
        $outdated = AiModel::query()->with('provider')->where('enabled', true)->get()
            ->filter(fn (AiModel $model) => $catalog->newerPrices($model) !== null)
            ->pluck('display_name');
        $this->check('Catalog prices are current', $outdated->isEmpty(), warnOnly: true, hint: 'New catalog prices for: '.$outdated->implode(', ').'. Admin > Models: "Use new prices".');

        // An alias that allows web search on a model that cannot search (or
        // has no search price) never offers it to users.
        $noSearch = ModelAlias::query()->with('aiModel')->where('enabled', true)->where('web_search_enabled', true)->get()
            ->filter(fn (ModelAlias $alias) => $alias->webSearchMaxUses() === null)
            ->map(fn (ModelAlias $alias) => $alias->slug);
        $this->check('Aliases with web search can search', $noSearch->isEmpty(), warnOnly: true, hint: 'The model of '.$noSearch->implode(', ').' does not support web search or has no search price. Admin > Models.');

        // Google's terms let grounded answers be kept for at most two years.
        $gemini = ModelAlias::query()->with('aiModel.provider')->where('enabled', true)->get()
            ->contains(fn (ModelAlias $alias) => $alias->webSearchMaxUses() !== null && $alias->aiModel->provider->driver === ProviderDriver::Gemini);
        $retention = app(PrivacySettings::class)->conversation_retention_days;
        $this->check('Answers grounded in Google Search are kept at most two years', ! $gemini || ($retention !== null && $retention <= 730), warnOnly: true, hint: 'A Gemini alias allows web search: set conversation retention to 730 days or less (Admin > Privacy), as Google\'s terms require.');

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

    /**
     * OpenID Connect, when turned on: settings, reachability of the
     * discovery document and keys, and the clock (ID tokens are checked with
     * 60 seconds of leeway).
     */
    private function checkOidc(OidcIdentityProvider $oidc): void
    {
        $details = $oidc->setupDetails();

        if (! $details['enabled']) {
            return;
        }

        $this->check('OpenID Connect settings are complete', $details['problem'] === null, hint: (string) $details['problem']);

        if ($details['problem'] !== null) {
            return;
        }

        try {
            $skew = $oidc->discovery()->test();
            $this->check('OpenID Connect provider is reachable', true);
        } catch (OidcUnavailable $exception) {
            $this->check('OpenID Connect provider is reachable', false, hint: $exception->getMessage());

            return;
        }

        if ($skew !== null) {
            $this->check("Clock matches the identity provider ({$skew} s)", abs($skew) <= 30, warnOnly: abs($skew) <= 60, hint: 'Synchronize the server clock (NTP); ID tokens are refused beyond 60 seconds.');
        }
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

    /**
     * The single file driver grows forever; daily files, stderr and syslog
     * are rotated by Laravel or by the system.
     */
    private function logsRotate(): bool
    {
        $default = (string) config('logging.default');
        $channels = config("logging.channels.{$default}.driver") === 'stack'
            ? (array) config("logging.channels.{$default}.channels", [])
            : [$default];

        foreach ($channels as $channel) {
            if (config('logging.channels.'.$channel.'.driver') === 'single') {
                return false;
            }
        }

        return true;
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
