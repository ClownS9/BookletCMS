<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Models\User;
use App\Support\AdminNav;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin reads like a book: front matter in roman numerals, chapters
 * and appendices in order, left pages even and right pages odd.
 */
class BookPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
    }

    public function test_pages_are_numbered_like_a_book(): void
    {
        $book = collect(AdminNav::book())->keyBy('key');

        $this->assertSame(['i', 'ii'], [$book['contents']['left'], $book['contents']['right']]);
        $this->assertSame(['2', '3'], [$book['list:posts']['left'], $book['list:posts']['right']]);
        $this->assertSame('Chapter 1', $book['list:posts']['chapter']);
        $this->assertSame('Chapter 2', $book['list:pages']['chapter']);
        $this->assertSame('Chapter 3', $book['media']['chapter']);
        $this->assertSame('Ex libris', $book['account']['chapter']);

        // Every left page even, every right page odd, and no number used twice.
        $main = collect(AdminNav::book())->where('part', '!=', 'front');
        $main->each(fn ($s) => $this->assertTrue((int) $s['left'] % 2 === 0 && (int) $s['right'] === (int) $s['left'] + 1));
        $this->assertSame($main->count(), $main->pluck('left')->unique()->count());
    }

    public function test_a_new_collection_becomes_a_new_chapter(): void
    {
        Booklet::collections()->make(['handle' => 'recipes', 'title' => 'Recipes'], ['route' => '/recipes/{slug}'])->save();
        request()->attributes->remove('booklet.book');

        $book = collect(AdminNav::book())->keyBy('key');

        $this->assertSame('Chapter 3', $book['list:recipes']['chapter']);
        $this->assertSame('Chapter 4', $book['media']['chapter']);
    }

    public function test_the_admin_shows_its_page_numbers_and_contents(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get(route('admin.entries', 'posts'))
            ->assertSee('Chapter 1 · ', false)
            ->assertSeeInOrder(['page__number">2<', 'page__number">3<'], false);

        $this->actingAs($user)->get(route('admin.contents'))
            ->assertSee('Table of contents')
            ->assertSeeInOrder(['Posts', 'Pages', 'Media', 'People', 'Comments', 'Appendix A'], false);
    }
}
