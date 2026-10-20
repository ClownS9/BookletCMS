<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Http\Middleware\ApplySiteSettings;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The site's timezone: chosen in Settings, or APP_TIMEZONE, for pages and the scheduler alike.
 */
class SiteTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected string $was;

    protected function setUp(): void
    {
        parent::setUp();
        $this->was = date_default_timezone_get();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->was);
        parent::tearDown();
    }

    protected function choose(?string $timezone): void
    {
        Booklet::globals('site')->set('timezone', $timezone)->save();
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);
    }

    public function test_settings_win_then_app_timezone_then_utc(): void
    {
        config(['booklet.timezone' => 'Asia/Kuala_Lumpur']);
        $this->choose('Asia/Brunei');
        ApplySiteSettings::applyTimezone();
        $this->assertSame('Asia/Brunei', config('app.timezone'));
        $this->assertSame('Asia/Brunei', date_default_timezone_get());

        $this->choose(null);
        $this->assertSame('Asia/Kuala_Lumpur', ApplySiteSettings::timezone());

        config(['booklet.timezone' => 'Not/AZone']);
        $this->assertNull(ApplySiteSettings::timezone());
    }
}
