<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Models\Comment;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeding the starter book twice, or seeding and then running First setup,
 * never leaves two of anything.
 */
class StarterSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
    }

    public function test_seeding_twice_does_not_duplicate_pages_or_posts(): void
    {
        $this->seed(BookletStarterSeeder::class);
        Booklet::globals('site')->merge(['site_name' => 'My own name'])->save();

        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        Booklet::flush();

        $pages = Booklet::entries()->whereCollection('pages')->map->slug()->sort()->values()->all();
        $this->assertSame(['about', 'contact', 'home'], $pages);
        $this->assertCount(1, Booklet::entries()->whereCollection('posts'));
        $this->assertSame(1, Comment::count());
        $this->assertCount(3, Booklet::trees()->collection('pages')->entryIds());
        $this->assertSame('My own name', Booklet::globals('site')->get('site_name'));
    }

    public function test_tidy_removes_untouched_copies_and_keeps_edited_ones(): void
    {
        $this->seed(BookletStarterSeeder::class);

        // What an older, non-repeatable seed left behind.
        $copy = Booklet::entries()->make(['collection' => 'pages', 'slug' => 'about', 'blueprint' => 'page'],
            ['title' => 'About', 'content' => 'A few lines about who writes here.'])->save();
        $edited = Booklet::entries()->make(['collection' => 'pages', 'slug' => 'contact', 'blueprint' => 'page'],
            ['title' => 'Contact', 'content' => 'Changed by hand.'])->save();
        $this->assertSame('about-2', $copy->slug());

        $this->artisan('booklet:tidy-duplicates', ['--force' => true])->assertSuccessful();
        Booklet::flush();

        $this->assertNull(Booklet::entries()->find($copy->id()));
        $this->assertNotNull(Booklet::entries()->find($edited->id()));
    }
}
