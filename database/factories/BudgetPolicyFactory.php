<?php

namespace Database\Factories;

use App\Models\BudgetPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetPolicy>
 */
class BudgetPolicyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'monthly_limit_usd' => '10',
        ];
    }
}
