<?php

namespace App\Domain\AI\Actions;

use App\Domain\AI\Exceptions\ProviderException;
use App\Domain\AI\Services\ProviderManager;
use App\Models\Provider;

/**
 * Validates a provider's credential with a model listing call (no tokens
 * are spent). Returns null on success, otherwise a stable error code.
 */
final class CheckProviderConnection
{
    public function __construct(private readonly ProviderManager $providers) {}

    public function handle(Provider $provider): ?string
    {
        try {
            $this->providers->forProvider($provider)->checkConnection();
        } catch (ProviderException $exception) {
            return $exception->code();
        }

        return null;
    }
}
