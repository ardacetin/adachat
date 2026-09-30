<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\RedirectIdentityProvider;

/**
 * The identity providers an institution can sign in with.
 */
final class IdentityProviderRegistry
{
    /** @var array<string, RedirectIdentityProvider> */
    private array $providers = [];

    /**
     * @param  iterable<RedirectIdentityProvider>  $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    public function find(string $key): ?RedirectIdentityProvider
    {
        $provider = $this->providers[$key] ?? null;

        return $provider !== null && $provider->isEnabled() ? $provider : null;
    }

    /**
     * @return list<string>
     */
    public function enabledKeys(): array
    {
        return array_keys(array_filter(
            $this->providers,
            static fn (RedirectIdentityProvider $provider): bool => $provider->isEnabled(),
        ));
    }
}
