<?php

namespace App\Providers;

use App\Domain\AI\Catalog\ModelCatalog;
use App\Domain\PersonalData\PersonalDataScanner;
use App\Domain\PersonalData\PersonalDataSettings;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Spatie\LaravelSettings\Support\SettingsCacheFactory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ModelCatalog::class, fn () => new ModelCatalog((string) config('ada.catalog.path')));
        $this->app->bind(PersonalDataScanner::class, fn ($app) => PersonalDataScanner::fromSettings($app->make(PersonalDataSettings::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimits();
        $this->configureProxies();
        $this->clearSettingsCacheAfterMigrations();
    }

    /**
     * Settings are cached whole: after an upgrade adds a setting, the cached
     * copy would lack it until the cache is cleared, so every migration run
     * clears it.
     */
    protected function clearSettingsCacheAfterMigrations(): void
    {
        Event::listen(MigrationsEnded::class, function (): void {
            foreach ($this->app->make(SettingsCacheFactory::class)->all() as $cache) {
                if ($cache->isEnabled()) {
                    $cache->clear();
                }
            }
        });
    }

    /**
     * Behind a reverse proxy the client address and the scheme come from
     * X-Forwarded-* headers, which are believed only from the configured
     * proxies (docs/deployment.md). They decide the IP in the audit log and
     * the rate limits, secure cookies and HSTS.
     *
     * "*" means the immediate peer only. Laravel would read it as every
     * address, and a chain of trusted hops resolves to its left-most entry,
     * which the client writes itself.
     */
    protected function configureProxies(): void
    {
        $proxies = trim((string) config('ada.http.trusted_proxies'));

        // X-Forwarded-Prefix and the AWS ELB headers are not believed: no
        // supported proxy sets them, and a client could otherwise choose the
        // path prefix of the URLs Ada generates for its request.
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);

        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? ['REMOTE_ADDR'] : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
        }

        // Links and redirects use https whenever the site is served over
        // https, even if a proxy in front forgets to say so.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceHttps();
        }
    }

    /**
     * Per-user ceilings on top of the per-route limits (docs/security.md §7):
     * generous for people, tight enough to stop a runaway script. Chat
     * requests are also limited by the group's requests per minute.
     */
    protected function configureRateLimits(): void
    {
        RateLimiter::for('app', fn (Request $request) => Limit::perMinute(300)->by('app:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(30)->by('uploads:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(120)->by('admin:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Model::shouldBeStrict(! app()->isProduction());
    }
}
