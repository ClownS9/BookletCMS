<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Models\User;
use App\Support\YourBook;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The crumb switcher in the Dashboard layout: "Dashboard / Settings ⇕" opens
 * the pages beside this one, something new, and the section's settings.
 */
class CrumbSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        YourBook::save($this->admin, ['layout' => 'dashboard']);
        $this->admin = $this->admin->fresh();
    }

    protected function menu(string $html): string
    {
        $this->assertStringContainsString('class="crumb-switch"', $html);

        return substr($html, strpos($html, 'class="crumb-switch__menu"'), 6000);
    }

    public function test_a_settings_page_switches_between_settings_pages(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.settings.upkeep'))->assertOk()->getContent();
        $menu = $this->menu($html);

        $this->assertStringContainsString('href="'.route('admin.backups').'"', $menu);
        $this->assertStringContainsString('href="'.route('admin.settings').'"', $menu);
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('admin.settings.upkeep'), '#').'"[^>]*aria-current="page"#', $menu);
        // Settings is the switcher now, not also a link before it.
        $start = strpos($html, 'class="page-head__crumbs"');
        $trail = substr($html, $start, strpos($html, 'class="crumb-switch"', $start) - $start);
        $this->assertStringNotContainsString('href="'.route('admin.settings').'"', $trail);
        $this->assertStringContainsString('href="'.route('admin.contents').'"', $trail);
    }

    public function test_a_collection_lists_every_collection_with_new_and_settings(): void
    {
        $menu = $this->menu($this->actingAs($this->admin)->get(route('admin.entries', 'posts'))->assertOk()->getContent());

        $this->assertStringContainsString('href="'.route('admin.entries', 'pages').'"', $menu);
        $this->assertStringContainsString('href="'.route('admin.calendar').'"', $menu);
        $this->assertStringContainsString('href="'.route('admin.entries.create', 'posts').'"', $menu);
        $this->assertStringContainsString('New post', $menu);
        $this->assertStringContainsString('href="'.route('admin.collections', ['chapter' => 'posts']).'"', $menu);
    }

    public function test_people_offers_an_invite_to_admins_only(): void
    {
        $menu = $this->menu($this->actingAs($this->admin)->get(route('admin.readers'))->assertOk()->getContent());
        $this->assertStringContainsString('href="'.route('admin.people', ['invite' => 1]).'"', $menu);
        $this->assertStringContainsString('href="'.route('admin.subscribers').'"', $menu);
    }

    public function test_a_writer_sees_only_what_they_may_open(): void
    {
        $writer = User::factory()->create(['role' => 'writer']);
        YourBook::save($writer, ['layout' => 'dashboard']);

        $menu = $this->menu($this->actingAs($writer->fresh())->get(route('admin.entries', 'posts'))->assertOk()->getContent());

        $this->assertStringNotContainsString('Collection settings', $menu);
        $this->assertStringNotContainsString(route('admin.settings'), $menu);
    }

    public function test_your_own_pages_switch_between_each_other(): void
    {
        $menu = $this->menu($this->actingAs($this->admin)->get(route('admin.account.yours'))->assertOk()->getContent());

        $this->assertStringContainsString('href="'.route('admin.account').'"', $menu);
        $this->assertStringContainsString('href="'.route('admin.account.menu').'"', $menu);
    }

    public function test_the_book_layout_keeps_its_own_back_links(): void
    {
        YourBook::save($this->admin, ['layout' => 'book']);

        $this->actingAs($this->admin->fresh())->get(route('admin.settings.upkeep'))->assertOk()->assertDontSee('crumb-switch', false);
    }
}
