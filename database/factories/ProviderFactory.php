<?php

namespace Database\Factories;

use App\Domain\AI\Enums\ProviderDriver;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Provider>
 */
class ProviderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'driver' => ProviderDriver::OpenAI,
            'name' => fake()->company(),
        ];
    }

    public function driver(ProviderDriver $driver): static
    {
        return $this->state(fn (array $attributes) => ['driver' => $driver]);
    }
}
