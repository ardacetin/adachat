<?php

namespace App\Models;

use App\Domain\Identity\Enums\Appearance;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * A person who can sign in. Ada has no passwords: users authenticate through
 * an external identity provider (Google Workspace in V1).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $avatar_url
 * @property UserRole $role
 * @property int $group_id
 * @property string|null $locale
 * @property Appearance $appearance
 * @property UserStatus $status
 * @property Carbon|null $disabled_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $last_active_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Group $group
 */
#[Fillable(['name', 'email', 'avatar_url', 'locale', 'appearance'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Default attribute values, mirroring the database defaults.
     *
     * @var array<string, string|null>
     */
    protected $attributes = [
        'avatar_url' => null,
        'locale' => null,
        'role' => 'user',
        'appearance' => 'system',
        'status' => 'active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'appearance' => Appearance::class,
            'status' => UserStatus::class,
            'disabled_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return HasMany<UserIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /**
     * Lower-case e-mail addresses so that comparisons are exact.
     */
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim($value));
    }

    /**
     * Ada has no passwords; the session guard still asks for one.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }
}
