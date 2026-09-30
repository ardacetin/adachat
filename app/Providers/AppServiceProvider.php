<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimits();
    }

    /**
     * Per-user ceilings on top of the per-route limits (docs/security.md §7):
     * generous for people, tight enough to stop a runaway script. Chat
     * requests are also limited by the group's requests per minute.
     */
    protected function configureRateLimits(): void
    {
        RateLimiter::for('app', fn (Request $request) => Limit::perMinute(300)->by('app:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
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
