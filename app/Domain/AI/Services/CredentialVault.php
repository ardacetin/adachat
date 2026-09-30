<?php

namespace App\Domain\AI\Services;

use App\Domain\Audit\AuditLogger;
use App\Models\Provider;
use App\Models\ProviderCredential;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Stores and rotates provider API keys. Keys are encrypted at rest, never
 * returned to the browser and never written to the audit log.
 */
final class CredentialVault
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function rotate(Provider $provider, string $secret): ProviderCredential
    {
        $secret = trim($secret);

        $credential = DB::transaction(function () use ($provider, $secret): ProviderCredential {
            $provider->credentials()
                ->where('is_active', true)
                ->update(['is_active' => false, 'rotated_at' => now()]);

            $credential = new ProviderCredential;
            $credential->forceFill([
                'provider_id' => $provider->id,
                'secret' => $secret,
                'last_four' => mb_substr($secret, -4),
                'is_active' => true,
                'created_by' => Auth::id(),
            ])->save();

            return $credential;
        });

        $this->audit->record('provider.credential_rotated', $provider, [], ['last_four' => $credential->last_four]);

        $provider->unsetRelation('activeCredential');

        return $credential;
    }

    /**
     * Display form of the active key, e.g. "••••8f2a".
     */
    public static function mask(?ProviderCredential $credential): ?string
    {
        return $credential === null ? null : '••••'.$credential->last_four;
    }
}
