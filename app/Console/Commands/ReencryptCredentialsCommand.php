<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Models\ProviderCredential;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Second step of an APP_KEY rotation (docs/deployment.md): with the new key
 * in APP_KEY and the old one in APP_PREVIOUS_KEYS, re-encrypts every stored
 * provider API key with the new key, after which the old key can be
 * removed. Secrets are never printed.
 */
#[Signature('ada:credentials:reencrypt')]
#[Description('Re-encrypt stored provider API keys with the current APP_KEY')]
class ReencryptCredentialsCommand extends Command
{
    public function handle(AuditLogger $audit): int
    {
        $done = 0;
        $failed = [];

        foreach (ProviderCredential::query()->with('provider')->lazyById() as $credential) {
            try {
                $secret = Crypt::decryptString((string) $credential->getRawOriginal('secret'));
            } catch (DecryptException) {
                $failed[] = "#{$credential->id} ({$credential->provider->name}, ••••{$credential->last_four})";

                continue;
            }

            // Written directly: Eloquent sees the same plain value and
            // would skip the update.
            ProviderCredential::query()->whereKey($credential->id)->update(['secret' => Crypt::encryptString($secret)]);
            $done++;
        }

        if ($done > 0) {
            $audit->record('credentials.reencrypted', null, [], ['count' => $done]);
        }

        $this->components->info("Re-encrypted {$done} provider API key(s) with the current APP_KEY.");

        if ($failed !== []) {
            $this->components->error('These keys could not be decrypted with APP_KEY or APP_PREVIOUS_KEYS; enter them again in Admin > Providers:');
            $this->components->bulletList($failed);

            return self::FAILURE;
        }

        $this->components->info('APP_PREVIOUS_KEYS can now be removed.');

        return self::SUCCESS;
    }
}
