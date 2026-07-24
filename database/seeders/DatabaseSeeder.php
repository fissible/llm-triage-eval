<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Local dev admin for the Filament panel (matches README).
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@triage.test',
            'password' => bcrypt('password'),
        ]);
    }
}
