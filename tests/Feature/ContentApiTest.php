<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\ContentApiSettings;
use App\Models\ApiKey;
use App\Models\User;
use App\Support\ContentApi;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The read-only content API: off until it's turned on, keys made in
 * Settings, published content only, and browsers on other sites only
 * where they're allowed.
 */
class ContentApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $key;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        @unlink(\App\Support\StaticSite::privatePath('content-api.json'));
        ContentApi::saveSettings(['on' => true]);
        [, $this->key] = ApiKey::issue('The phone app', null);
    }

    protected function tearDown(): void
    {
        @unlink(\App\Support\StaticSite::privatePath('content-api.json'));
        parent::tearDown();
    }

    protected function ask(string $path, array $headers = [])
    {
        return $this->getJson('/api/v1'.$path, ['Authorization' => 'Bearer '.$this->key] + $headers);
    }

    public function test_off_until_turned_on_and_a_key_is_needed(): void
    {
        ContentApi::saveSettings(['on' => false]);
        $this->ask('/site')->assertNotFound()->assertJsonPath('error', fn ($e) => str_contains($e, 'turned off'));

        ContentApi::saveSettings(['on' => true]);
        $this->getJson('/api/v1/site')->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->getJson('/api/v1/site', ['Authorization' => 'Bearer bk_'.str_repeat('x', 40)])->assertUnauthorized();
        $this->ask('/site')->assertOk()->assertJsonPath('data.name', app(\App\Booklet\Site::class)->name());
        $this->getJson('/api/v1/site?key='.$this->key)->assertOk();

        $key = ApiKey::first();
        $this->assertSame(2, $key->uses);
        $this->assertNotNull($key->last_used_at);
        $this->assertNotSame($this->key, $key->key_hash, 'only a fingerprint is kept');
        $this->assertStringEndsWith($key->last4, $this->key);

        $key->forceFill(['revoked_at' => now()])->save();
        $this->ask('/site')->assertUnauthorized();

        ContentApi::saveSettings(['open' => true]);
        $this->getJson('/api/v1/site')->assertOk()->assertHeader('Cache-Control', 'max-age=60, public');
    }

    public function test_published_posts_only_newest_first_with_filters_and_pages(): void
    {
        foreach (range(1, 4) as $n) {
            Booklet::entries()->make(['collection' => 'posts', 'slug' => 'kuih-'.$n, 'date' => now()->subDays($n)->format('Y-m-d H:i:s')],
                ['title' => 'Kuih number '.$n, 'content' => 'Steamed **pandan** layers.', 'tags' => $n % 2 ? ['recipes'] : []])->save();
        }
        Booklet::entries()->make(['collection' => 'posts', 'slug' => 'secret', 'published' => false], ['title' => 'A secret draft'])->save();
        Booklet::entries()->make(['collection' => 'posts', 'slug' => 'later', 'date' => now()->addWeek()->format('Y-m-d H:i:s')], ['title' => 'Not out yet'])->save();

        $all = $this->ask('/collections/posts/entries?limit=100')->assertOk()->json();
        $titles = array_column($all['data'], 'title');
        $this->assertNotContains('A secret draft', $titles);
        $this->assertNotContains('Not out yet', $titles);
        $this->assertLessThan(array_search('Kuih number 2', $titles), array_search('Kuih number 1', $titles), 'newest first');
        $this->assertArrayNotHasKey('content', $all['data'][0], 'lists are light unless asked');

        $first = $all['data'][0];
        $this->assertStringStartsWith('http', $first['url']);
        $this->assertArrayHasKey('tags', $first['terms']);

        $tagged = $this->ask('/collections/posts/entries?tag=recipes')->json('data');
        $this->assertEqualsCanonicalizing(['Kuih number 1', 'Kuih number 3'], array_column($tagged, 'title'));

        $this->assertSame(['Kuih number 2'], array_column($this->ask('/collections/posts/entries?search=number+2')->json('data'), 'title'));

        $paged = $this->ask('/collections/posts/entries?limit=2&page=2')->assertOk();
        $paged->assertJsonPath('meta.page', 2)->assertJsonPath('meta.per_page', 2)->assertJsonCount(2, 'data');
        $this->assertStringContainsString('page=3', (string) $paged->json('links.next'));
        $this->assertStringNotContainsString('key=', (string) $paged->json('links.next'), 'the key isn’t written into links');

        $this->ask('/collections/posts/entries?content=1&search=number+1')->assertJsonPath('data.0.content.html', fn ($html) => str_contains($html, '<strong>pandan</strong>'));
        $this->ask('/collections/nope/entries')->assertNotFound();
    }

    public function test_one_entry_in_full_and_by_address(): void
    {
        $about = app(\App\Booklet\Site::class)->published()->first(fn ($e) => $e->collectionHandle() === 'pages' && $e->slug() === 'about');
        $this->assertNotNull($about);

        $json = $this->ask('/entries/'.$about->id())->assertOk()->json('data');
        $this->assertSame('about', $json['slug']);
        $this->assertArrayHasKey('markdown', $json['content']);
        $this->assertArrayHasKey('fields', $json);
        $this->assertArrayHasKey('parent', $json, 'pages say where they sit');

        $this->ask('/find?uri=/about')->assertOk()->assertJsonPath('data.id', (string) $about->id());
        $this->ask('/find?uri=/nothing-here')->assertNotFound();

        $draft = Booklet::entries()->make(['collection' => 'posts', 'slug' => 'hush', 'published' => false], ['title' => 'Hush']);
        $draft->save();
        $this->ask('/entries/'.$draft->id())->assertNotFound();
    }

    public function test_menus_terms_and_collections(): void
    {
        $this->ask('/menus/header')->assertOk()->assertJsonPath('data.handle', 'header')->assertJsonStructure(['data' => ['items' => [['label', 'url', 'children']]]]);
        $this->ask('/menus/nope')->assertNotFound();
        $this->ask('/taxonomies/categories/terms')->assertOk()->assertJsonStructure(['data' => [['slug', 'title', 'count']]]);
        $this->ask('/collections')->assertOk()->assertJsonFragment(['handle' => 'posts']);
        $this->ask('')->assertOk()->assertJsonStructure(['endpoints' => ['entries']]);
    }

    public function test_browsers_only_on_allowed_websites(): void
    {
        $this->ask('/site', ['Origin' => 'https://evil.example'])->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');

        ContentApi::saveSettings(['origins' => ContentApi::parseOrigins("https://kopikuih.com/blog\n junk")]);
        $this->assertSame(['https://kopikuih.com'], ContentApi::settings()['origins']);
        $this->ask('/site', ['Origin' => 'https://kopikuih.com'])->assertHeader('Access-Control-Allow-Origin', 'https://kopikuih.com');
        $this->ask('/site', ['Origin' => 'https://evil.example'])->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->call('OPTIONS', '/api/v1/site', [], [], [], ['HTTP_ORIGIN' => 'https://kopikuih.com', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET'])
            ->assertNoContent()->assertHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type');
    }

    public function test_the_same_answer_twice_sends_nothing(): void
    {
        $first = $this->ask('/site');
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->ask('/site', ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_settings_make_a_key_once_and_switch_it_off(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ContentApi::saveSettings(['on' => false]);

        $page = Livewire::actingAs($admin)->test(ContentApiSettings::class)
            ->assertSee('Content API')
            ->call('make')->assertHasErrors('name')
            ->set('name', 'Aminah’s site')->call('make')->assertHasNoErrors();

        $fresh = (string) $page->get('fresh');
        $this->assertStringStartsWith(ApiKey::PREFIX, $fresh);
        $this->assertTrue(ContentApi::settings()['on'], 'making a key turns the API on');
        $this->getJson('/api/v1/site', ['Authorization' => 'Bearer '.$fresh])->assertOk();

        $page->call('forget')->assertDontSee($fresh);
        $made = ApiKey::where('name', 'Aminah’s site')->first();
        $page->call('revoke', $made->id)->assertDispatched('stamp');
        $this->getJson('/api/v1/site', ['Authorization' => 'Bearer '.$fresh])->assertUnauthorized();

        $page->set('origins', "https://kopikuih.com, *")->call('save');
        $this->assertSame(['*'], ContentApi::settings()['origins']);

        $writer = User::factory()->create(['role' => 'writer']);
        $this->actingAs($writer)->get(route('admin.settings.api'))->assertForbidden();
    }
}
