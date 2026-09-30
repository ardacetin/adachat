<?php

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModel>
 */
class AiModelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'provider_model_id' => 'model-'.fake()->unique()->bothify('??##'),
            'display_name' => fake()->words(2, true),
            'input_price_per_million' => '1.250000',
            'output_price_per_million' => '10.000000',
            'context_window' => 200000,
            'max_output_tokens' => 8192,
        ];
    }
}
