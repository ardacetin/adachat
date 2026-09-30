<?php

namespace Database\Factories;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Enums\UserStatus;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'group_id' => fn () => Group::default()->id,
            'remember_token' => Str::random(10),
            // Test and sample users have read the usage notice; see unacknowledged().
            'acknowledged_version' => 1,
            'acknowledged_at' => now(),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => UserRole::Admin]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => UserRole::SuperAdmin]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Disabled,
            'disabled_at' => now(),
        ]);
    }

    /**
     * A user who has not acknowledged the usage notice yet (a first sign-in).
     */
    public function unacknowledged(): static
    {
        return $this->state(fn (array $attributes) => ['acknowledged_version' => null, 'acknowledged_at' => null]);
    }
}
