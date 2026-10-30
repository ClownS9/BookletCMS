<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Contents;
use App\Livewire\Admin\Health as HealthPage;
use App\Models\User;
use App\Stacy\ToolCall;
use App\Stacy\Tools;
use App\Support\Health;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The check-up: Settings › Check-up, booklet:doctor and Stacy.
 */
class HealthTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        config(['cache.default' => 'file']);
        Cache::flush();
    }

    protected function check(string $key, ?string $url = null): array
    {
        return collect(Health::run($url))->firstWhere('key', $key) ?? $this->fail("No {$key} check");
    }

    public function test_the_scheduler_is_known_to_run_or_not(): void
    {
        $never = $this->check('scheduler');
        $this->assertSame('fail', $never['state']);
        $this->assertStringContainsString('php booklet schedule:run', $never['command']);
        $this->assertStringContainsString(base_path(), $never['command']);

        Cache::forever(Health::BEAT, now()->subMinutes(45)->timestamp);
        $this->assertStringContainsString('45 minutes ago', $this->check('scheduler')['text']);

        // The scheduler's own job leaves the mark.
        $this->artisan('schedule:run')->assertSuccessful();
        $this->assertSame('ok', $this->check('scheduler')['state']);
    }

    public function test_server_checks_say_what_they_found(): void
    {
        $this->assertSame('ok', $this->check('database')['state']);
        $this->assertSame('ok', $this->check('migrations')['state']);
        $this->assertSame('ok', $this->check('app_key')['state']);

        // A database change waiting, as after an update.
        DB::table('migrations')->where('migration', 'like', '%add_sources_to_inserts')->delete();
        $pending = $this->check('migrations');
        $this->assertSame('fail', $pending['state']);
        $this->assertSame('php booklet migrate --force', $pending['command']);

        config(['app.debug' => true]);
        $this->app['env'] = 'production';
        $this->assertSame('fail', $this->check('debug')['state']);
        $this->app['env'] = 'testing';

        config(['app.url' => 'https://kopi.example']);
        $this->assertSame('ok', $this->check('address')['state']);
        $this->assertSame('warn', $this->check('address', 'https://other.example')['state']);
        config(['app.url' => 'http://kopi.example']);
        $this->assertStringContainsString('https', $this->check('address')['text']);

        config(['mail.default' => 'log']);
        $this->assertSame('warn', $this->check('mail')['state']);

        $this->assertSame('warn', $this->check('backups')['state']); // none yet
    }

    public function test_problems_in_the_log_are_told_by_how_recent(): void
    {
        $log = storage_path('logs/laravel.log');
        $kept = is_file($log) ? file_get_contents($log) : null;
        $line = fn ($at, $what) => '['.$at->format('Y-m-d H:i:s').'] local.ERROR: '.$what.' {"exception":"[object] (...)"}'."\n";

        try {
            // Earlier today, while files were being copied in: calm.
            file_put_contents($log, $line(now()->subHours(2), 'Invalid route action: [App\\Http\\Controllers\\ConversationController].'));
            $this->travelTo(now()->setTime(max(3, now()->hour), 0));
            $calm = $this->check('log');
            $this->assertSame('ok', $calm['state']);
            $this->assertStringContainsString('None in the last hour', $calm['text']);
            $this->assertStringContainsString('copying in new files', $calm['text']);

            // Something just now: worth a look.
            file_put_contents($log, $line(now()->subMinutes(5), 'Division by zero'), FILE_APPEND);
            $now = $this->check('log');
            $this->assertSame('warn', $now['state']);
            $this->assertStringContainsString('1 problem in the last hour (1 earlier today)', $now['text']);
            $this->assertStringContainsString('Division by zero', $now['text']);
        } finally {
            $kept === null ? @unlink($log) : file_put_contents($log, $kept);
        }
    }

    public function test_one_failing_check_does_not_stop_the_others(): void
    {
        config(['booklet.inserts_path' => '/nonexistent/'.uniqid()]);
        $this->app->instance(\App\Inserts\InsertManager::class, new class extends \App\Inserts\InsertManager
        {
            public function safeMode(): bool
            {
                throw new \RuntimeException('Shelf on fire');
            }
        });

        $checks = collect(Health::run());
        $this->assertStringContainsString('Shelf on fire', $checks->firstWhere('key', 'inserts')['text']);
        $this->assertSame('ok', $checks->firstWhere('key', 'database')['state']);
    }

    public function test_admins_see_the_check_up_and_others_do_not(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(HealthPage::class)
            ->assertSee('Check-up')
            ->assertSee('to fix')
            ->assertSee('Scheduler')
            ->assertSee('php booklet schedule:run')
            ->assertSee('Every check');

        // Contents nudges admins about what's broken.
        Livewire::test(Contents::class)->assertSee('The server check-up found')->assertSee(route('admin.settings.health'), false);

        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get(route('admin.settings.health'))->assertForbidden();
        Livewire::test(Contents::class)->assertDontSee('The server check-up found');

        $this->assertNotContains('site_health', array_column((new Tools($editor))->definitions(), 'name'));
        $result = json_decode((new Tools($this->admin))->run(new ToolCall('t', 'site_health', [])), true);
        $this->assertContains('Scheduler', array_column($result['needs_attention'], 'what'));
        $this->assertContains('Database', $result['fine']);
    }

    public function test_the_doctor_command(): void
    {
        $this->artisan('booklet:doctor')
            ->expectsOutputToContain('Running on its own')
            ->expectsOutputToContain('php booklet schedule:run')
            ->assertExitCode(1);

        Cache::forever(Health::BEAT, now()->timestamp);
        config(['mail.default' => 'smtp']);
        \Illuminate\Support\Facades\Storage::fake('local');
        \App\Support\Backups::create('manual');

        // What's left to fix is only what this computer lacks (its PHP), never what the book can set right.
        $left = collect(Health::run())->where('state', 'fail')->pluck('key')->diff(['php', 'extensions', 'folders', 'storage_link', 'space'])->values()->all();
        $this->assertSame([], $left);
        $this->artisan('booklet:doctor')->assertExitCode(collect(Health::run())->contains('state', 'fail') ? 1 : 0);
    }
}
