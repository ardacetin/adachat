<?php

namespace App\Providers;

use App\Domain\Identity\Actions\LoginUser;
use App\Domain\Identity\Providers\GoogleIdentityProvider;
use App\Domain\Identity\Services\AllowedDomainPolicy;
use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Domain\Institution\Settings\AuthSettings;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory as Socialite;

/**
 * Wires the identity domain. Sign-in policy comes from AuthSettings (admin
 * panel); adding a protocol means registering another provider here.
 */
class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AllowedDomainPolicy::class, fn (Application $app): AllowedDomainPolicy => new AllowedDomainPolicy(
            $app->make(AuthSettings::class)->allowed_domains,
        ));

        $this->app->bind(GoogleIdentityProvider::class, fn (Application $app): GoogleIdentityProvider => new GoogleIdentityProvider(
            $app->make(Socialite::class),
            $app->make(AuthSettings::class)->allowed_domains,
        ));

        $this->app->bind(IdentityProviderRegistry::class, fn (Application $app): IdentityProviderRegistry => new IdentityProviderRegistry([
            $app->make(GoogleIdentityProvider::class),
        ]));

        $this->app->bind(LoginUser::class, fn (Application $app): LoginUser => new LoginUser(
            $app->make(AllowedDomainPolicy::class),
            $app->make(AuthSettings::class)->auto_provision,
        ));
    }

    public function boot(): void
    {
        // Role → ability mapping. Policies build on these gates; UI flags
        // derived from them are cosmetic, never the security boundary.
        Gate::define('access-admin', fn (User $user): bool => $user->role->canAccessAdmin());
        Gate::define('manage-system', fn (User $user): bool => $user->role->canManageSystem());
    }
}
