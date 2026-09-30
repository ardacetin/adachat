<?php

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\ModelAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModelAlias>
 */
class ModelAliasFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $word = fake()->unique()->word();

        return [
            'slug' => $word,
            'name' => ['en' => ucfirst($word), 'tr' => ucfirst($word)],
            'ai_model_id' => AiModel::factory(),
        ];
    }
}
