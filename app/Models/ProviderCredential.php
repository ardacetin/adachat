<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An API key for a provider, encrypted at rest with APP_KEY. Written only
 * through App\Domain\AI\Services\CredentialVault.
 *
 * @property int $id
 * @property int $provider_id
 * @property string $secret
 * @property string $last_four
 * @property bool $is_active
 * @property int|null $created_by
 * @property CarbonImmutable|null $rotated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Hidden(['secret'])]
class ProviderCredential extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'is_active' => 'boolean',
            'rotated_at' => 'datetime',
        ];
    }
}
