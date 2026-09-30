<?php

namespace App\Providers;

use App\Domain\Identity\Actions\LoginUser;
use App\Domain\Identity\Providers\GoogleIdentityProvider;
use App\Domain\Identity\Services\AllowedDomainPolicy;
use App\Domain\Identity\Services\IdentityProviderRegistry;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory as Socialite;

/**
 * Wires the identity domain. Institution settings replace these config
 * values in M3; adding a protocol means registering another provider here.
 */
class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AllowedDomainPolicy::class, fn (): AllowedDomainPolicy => new AllowedDomainPolicy(
            $this->allowedDomains(),
        ));

        $this->app->bind(GoogleIdentityProvider::class, fn (Application $app): GoogleIdentityProvider => new GoogleIdentityProvider(
            $app->make(Socialite::class),
            $this->allowedDomains(),
        ));

        $this->app->bind(IdentityProviderRegistry::class, fn (Application $app): IdentityProviderRegistry => new IdentityProviderRegistry([
            $app->make(GoogleIdentityProvider::class),
        ]));

        $this->app->bind(LoginUser::class, fn (Application $app): LoginUser => new LoginUser(
            $app->make(AllowedDomainPolicy::class),
            (bool) config('ada.auth.auto_provision'),
        ));
    }

    public function boot(): void
    {
        // Role → ability mapping. Policies build on these gates; UI flags
        // derived from them are cosmetic, never the security boundary.
        Gate::define('access-admin', fn (User $user): bool => $user->role->canAccessAdmin());
        Gate::define('manage-system', fn (User $user): bool => $user->role->canManageSystem());
    }

    /**
     * @return list<string>
     */
    private function allowedDomains(): array
    {
        /** @var list<string> $domains */
        $domains = config('ada.auth.allowed_domains', []);

        return $domains;
    }
}
