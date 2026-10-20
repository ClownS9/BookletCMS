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
        // The starter book only. The first person is made by First setup (/setup),
        // which only opens while the book has nobody in it, so no account is made here.
        // For a throwaway demo account instead, set BOOKLET_DEMO_USER=true in .env.
        if (config('booklet.demo_user')) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
                'role' => 'admin',
            ]);
        }

        $this->call(BookletStarterSeeder::class);
    }
}
