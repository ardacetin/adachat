<?php

namespace App\Providers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Budget\Services\CurrentLimits;
use App\Domain\Identity\Actions\LoginUser;
use App\Domain\Identity\Oidc\IdTokenVerifier;
use App\Domain\Identity\Oidc\OidcDiscovery;
use App\Domain\Identity\Providers\OidcIdentityProvider;
use App\Domain\Identity\Providers\SamlIdentityProvider;
use App\Domain\Identity\Services\AllowedDomainPolicy;
use App\Domain\Identity\Services\GroupMapping;
use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Domain\Institution\Settings\AuthSettings;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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

        $this->app->bind(SamlIdentityProvider::class, fn (Application $app): SamlIdentityProvider => new SamlIdentityProvider(
            (array) config('ada.auth.saml'),
            $app->make(Cache::class),
            (string) config('app.url'),
        ));

        $this->app->bind(OidcIdentityProvider::class, fn (Application $app): OidcIdentityProvider => new OidcIdentityProvider(
            (array) config('ada.auth.oidc'),
            new OidcDiscovery(
                trim((string) config('ada.auth.oidc.issuer')),
                // Plain http only for a local test identity provider.
                (bool) $app->environment('local', 'testing'),
                $app->make(Cache::class),
            ),
            new IdTokenVerifier,
            (string) config('app.url'),
        ));

        $this->app->bind(IdentityProviderRegistry::class, fn (Application $app): IdentityProviderRegistry => new IdentityProviderRegistry([
            $app->make(SamlIdentityProvider::class),
            $app->make(OidcIdentityProvider::class),
        ]));

        $this->app->bind(GroupMapping::class, fn (Application $app): GroupMapping => new GroupMapping(
            $app->make(AuthSettings::class)->group_mapping,
            $app->make(AuthSettings::class)->group_mapping_unmatched,
        ));

        $this->app->bind(LoginUser::class, fn (Application $app): LoginUser => new LoginUser(
            $app->make(AllowedDomainPolicy::class),
            $app->make(AuthSettings::class)->auto_provision,
            $app->make(GroupMapping::class),
            $app->make(AuditLogger::class),
            $app->make(CurrentLimits::class),
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
