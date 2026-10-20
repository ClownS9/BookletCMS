<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_returns_a_successful_response(): void
    {
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        \App\Models\User::factory()->create(['role' => 'admin']);

        $this->get('/')->assertStatus(200);
    }
}
