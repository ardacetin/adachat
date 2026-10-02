<?php

namespace Database\Factories;

use App\Models\Assistant;
use App\Models\ModelAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assistant>
 */
class AssistantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'slug' => str($name)->slug()->value(),
            'name' => ['en' => ucfirst($name), 'tr' => ucfirst($name)],
            'description' => ['en' => 'Helps with '.$name, 'tr' => $name.' konusunda yardım eder'],
            'instructions' => 'You help students with '.$name.'.',
            'model_alias_id' => ModelAlias::factory(),
            'starter_prompts' => ['Where do I start?'],
        ];
    }
}
