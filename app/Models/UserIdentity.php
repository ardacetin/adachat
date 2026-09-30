<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's account at an external identity provider.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $subject
 * @property string $email
 * @property array<string, mixed>|null $last_claims
 * @property Carbon|null $last_login_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserIdentity extends Model
{
    /**
     * Identities are only written by the LoginUser action.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_claims' => 'array',
            'last_login_at' => 'datetime',
        ];
    }
}
