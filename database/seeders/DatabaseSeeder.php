<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed sample data for local development.
     */
    public function run(): void
    {
        User::factory()->superAdmin()->create([
            'name' => 'Sample Super Admin',
            'email' => 'superadmin@example.edu',
        ]);

        User::factory()->admin()->create([
            'name' => 'Sample Admin',
            'email' => 'admin@example.edu',
        ]);

        User::factory()->create([
            'name' => 'Sample User',
            'email' => 'user@example.edu',
        ]);
    }
}
