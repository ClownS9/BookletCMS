<?php

namespace Tests\Feature;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\PageBuild;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Building pages and posts of sections, on the page itself: the page or
 * post stays what it is (its address, its chapter, its menus); only how
 * it's drawn changes. Readers see Booklet's own drawing until the first
 * publish, and only ever what's published after that.
 */
class PageBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(\App\Themes\ThemeManager::path());
        parent::tearDown();
    }

    protected function about(): Entry
    {
        return Booklet::entries()->findBySlug('pages', 'about') ?? $this->fail('no About page');
    }

    protected function act(string $target, string $action, mixed $payload = null, array $state = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->postJson(route('builder.act', $target), ['action' => $action, 'payload' => $payload, 'state' => $state]);
    }

    protected function build(Entry $entry): PageBuild
    {
        return PageBuild::forEntry($entry) ?? $this->fail('not built');
    }

    protected function reader(string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);

        return $this->get($url);
    }

    protected function startAbout(): PageBuild
    {
        $this->actingAs($this->admin)->postJson(route('builder.start', $this->about()->id()))->assertOk()
            ->assertJsonPath('go', url('/about').'?edit=1');

        return $this->build($this->about());
    }

    public function test_any_page_can_be_built_where_it_is(): void
    {
        $about = $this->about();

        // Not built: Booklet draws it; people who can build it get "Build this page".
        $this->reader('/about')->assertOk()->assertSee('A few lines about who writes here.')->assertDontSee('pb-editor', false);
        $this->actingAs($this->admin)->get('/about')->assertOk()->assertSee('id="pb-editor"', false)
            ->assertSee('data-begin="'.route('builder.start', $about->id()).'"', false)->assertSee('Build this page');
        $writer = User::factory()->create(['role' => 'writer']);
        $this->actingAs($writer)->get('/about')->assertDontSee('pb-editor', false);
        $this->actingAs($writer)->postJson(route('builder.start', $about->id()))->assertNotFound();

        // Starting: its own words, as sections. Readers still see the page as it was.
        $page = $this->startAbout();
        $this->assertSame(['hero'], array_column($page->draft, 'type'));
        $this->assertSame(['About', 'A few lines about who writes here.'], [$page->draft[0]['fields']['title'], $page->draft[0]['fields']['lede']]);
        $this->assertNull($page->published);
        $this->reader('/about')->assertSee('A few lines about who writes here.')->assertDontSee('pb-page', false);
        $this->actingAs($this->admin)->get('/about')->assertSee('data-pb-page="'.$about->id().'"', false)->assertSee('data-start="1"', false)
            ->assertSee('noindex', false);
        $this->actingAs($this->admin)->getJson(route('builder.edit', $about->id()))->assertOk()
            ->assertJsonPath('bar.status', 'changed')->assertJsonPath('bar.words', 'Changes not published')->assertJsonPath('bar.address', '/about');

        // Building, then publishing: same address, same page.
        $this->act($about->id(), 'set', ['id' => $page->draft[0]['id'], 'field' => 'title', 'value' => 'Who we are']);
        $this->act($about->id(), 'add', 'faq', ['section' => $page->draft[0]['id']]);
        $this->act($about->id(), 'publish')->assertJsonPath('stamp', 'Published. Readers see the new version now.')->assertJsonPath('bar.status', 'live');
        $html = $this->reader('/about')->assertOk()->assertSee('<h1 class="pb-title pb-title--big">Who we are</h1>', false)->assertSee('pb--faq', false)
            ->assertDontSee('pb-editor', false)->assertDontSee('data-pb-field', false)->getContent();
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertSame('A few lines about who writes here.', $this->about()->get('content'), 'its own words are left as they were');
        $this->assertSame('/about', $this->about()->uri());
        $this->actingAs($this->admin)->get('/about')->assertSee('Edit this page')->assertSee('data-start="0"', false);

        // In the book: it says so.
        \Livewire\Livewire::actingAs($this->admin)->test(\App\Livewire\Admin\EntryEditor::class, ['collection' => 'pages', 'entry' => $about->id()])
            ->assertSee('Built on the page')->assertSee('On the site')->assertSee(url('/about').'?edit=1');
    }

    public function test_editing_undo_and_the_phone_view(): void
    {
        $about = $this->about();
        $page = $this->startAbout();
        $hero = $page->draft[0]['id'];

        $this->act($about->id(), 'set', ['id' => $hero, 'field' => 'title', 'value' => 'Who'], [], null);
        $this->act($about->id(), 'set', ['id' => $hero, 'field' => 'title', 'value' => 'Who we are']);
        $this->act($about->id(), 'copy', $hero);
        $this->assertCount(2, $this->build($about)->draft);
        $this->act($about->id(), 'undo');
        $this->assertCount(1, $this->build($about)->draft);
        $this->act($about->id(), 'undo');
        $this->assertSame('About', $this->build($about)->draft[0]['fields']['title'], 'a run of typing is one Undo step');
        $this->act($about->id(), 'remove', $hero)->assertJsonPath('stamp', 'Taken out. (Undo puts it back.)');
        $this->act($about->id(), 'undo');

        // The phone view: the draft, without the editor.
        $this->act($about->id(), 'set', ['id' => $hero, 'field' => 'title', 'value' => 'Draft title']);
        $this->actingAs($this->admin)->get(route('builder.preview', $about->id()))->assertRedirect(route('preview', $about->id()).'?preview=1');
        $this->actingAs($this->admin)->get(route('preview', $about->id()).'?preview=1')->assertSee('Draft title')->assertDontSee('id="pb-editor"', false);
        $this->act($about->id(), 'set', ['id' => $hero, 'field' => 'nope', 'value' => 'x'])->assertStatus(422)
            ->assertJsonPath('errors', ['field:nope' => 'That isn’t part of this section.']);

        // A value that can't go there: said under its field, kept as typed to fix.
        $r = $this->act($about->id(), 'set', ['id' => $hero, 'field' => 'button.url', 'value' => 'javascript:alert(1)'], ['section' => $hero])->assertStatus(422);
        $this->assertSame(['field:button.url' => 'Use an address like /about, https://example.com, or an email address.'], $r->json('errors'));
        $drawer = $r->json('drawer');
        $this->assertStringContainsString('value="javascript:alert(1)"', $drawer);
        $this->assertStringContainsString('aria-invalid="true"', $drawer);
        $this->assertSame('', (string) ($this->build($about)->draft[0]['fields']['button']['url'] ?? ''), 'nothing kept');

        // A list's things are only the ones there (no gaps past the end).
        $this->act($about->id(), 'add', 'faq');
        $faq = collect($this->build($about)->draft)->firstWhere('type', 'faq')['id'];
        $this->assertSame(['field:items.7.question' => 'That isn’t in the list any more.'],
            $this->act($about->id(), 'set', ['id' => $faq, 'field' => 'items.7.question', 'value' => 'Far away?'])->assertStatus(422)->json('errors'));
        $this->act($about->id(), 'set', ['id' => $faq, 'field' => 'items.1.question', 'value' => 'Second?'])->assertOk();
        $this->assertSame(['A question people ask?', 'Second?'], array_column(collect($this->build($about)->draft)->firstWhere('type', 'faq')['fields']['items'], 'question'));
        $this->act($about->id(), 'whatever')->assertNotFound();
    }

    public function test_its_name_address_home_page_and_going_back_to_normal(): void
    {
        $about = $this->about();
        $this->startAbout();
        $this->act($about->id(), 'publish');

        $this->act($about->id(), 'details', ['name' => '', 'address' => 'x'])->assertStatus(422)->assertJsonPath('errors.name', 'The name field is required.');
        $this->act($about->id(), 'details', ['name' => 'Our story', 'address' => 'Our Story', 'description' => 'How it began.'])
            ->assertJsonPath('stamp', 'Saved. /about now sends readers to /our-story.')->assertJsonPath('moved', url('/our-story'));
        $entry = Booklet::entries()->find($about->id());
        $this->assertSame(['Our story', '/our-story', 'How it began.'], [$entry->title(), $entry->uri(), $entry->get('meta_description')]);
        $this->reader('/about')->assertRedirect('/our-story');
        $this->reader('/our-story')->assertOk()->assertSee('pb-page', false);
        $this->act($about->id(), 'details', ['name' => 'Our story', 'address' => 'contact'])->assertJsonPath('stamp', 'Saved. That address was taken, so it’s /contact-2.');

        // The home page (admins only).
        $editor = User::factory()->create(['role' => 'editor']);
        $this->act($about->id(), 'home', true, [], $editor)->assertJsonPath('stamp', 'Only an admin can choose the home page.');
        $this->act($about->id(), 'home', true)->assertJsonPath('stamp', 'It’s the home page now.')->assertJsonPath('bar.address', '/');
        $this->reader('/')->assertOk()->assertSee('pb-page', false)->assertSee('<h1 class="pb-title pb-title--big">About</h1>', false);
        $this->act($about->id(), 'home', false)->assertJsonPath('stamp', 'Your latest posts are the home page again.');

        // Drawn as before: its own words again.
        $this->act($about->id(), 'stop')->assertJsonPath('gone', url('/contact-2'));
        $this->assertNull(PageBuild::forEntry($about));
        $this->reader('/contact-2')->assertOk()->assertSee('A few lines about who writes here.')->assertDontSee('pb-page', false);
    }

    public function test_a_new_page_is_built_from_a_layout_and_gets_its_words_when_published(): void
    {
        $entry = Booklet::entries()->make(['collection' => 'pages', 'published' => false], ['title' => 'Spring offer', 'content' => ''])->save();
        $this->actingAs($this->admin)->get(route('preview', $entry->id()))->assertOk()->assertSee('Build this page');
        $this->actingAs($this->admin)->postJson(route('builder.start', $entry->id()))->assertJsonPath('go', route('preview', $entry->id()).'?edit=1');
        $page = $this->build($entry);
        $this->assertSame(['hero', 'columns', 'text-picture', 'quotes', 'cta'], array_column($page->draft, 'type'));
        $this->assertSame('Spring offer', $page->draft[0]['fields']['title']);
        $this->assertTrue($page->fresh);
        $this->reader('/spring-offer')->assertNotFound();

        $this->act($entry->id(), 'publish')->assertJsonPath('stamp', 'It’s on the site at /spring-offer.')->assertJsonPath('moved', url('/spring-offer'));
        $this->assertTrue(Booklet::entries()->find($entry->id())->published());
        $this->assertStringContainsString('## Spring offer', (string) Booklet::entries()->find($entry->id())->get('content'), 'its words, for drawing without sections');
        $this->reader('/spring-offer')->assertOk()->assertSee('pb--cta', false);
        $this->assertDatabaseHas('activity_log', ['action' => 'published', 'subject_key' => $entry->id()]);

        // A draft again: off the site; on, at its preview.
        $this->act($entry->id(), 'status', ['status' => 'draft'])->assertJsonPath('stamp', 'Taken off the site. Only people who can edit it see it now.')
            ->assertJsonPath('moved', route('preview', $entry->id()));
        $this->reader('/spring-offer')->assertNotFound();
        $this->assertFalse(Booklet::entries()->find($entry->id())->published());
    }

    public function test_posts_keep_their_head_and_conversation(): void
    {
        $post = Booklet::entries()->make(['collection' => 'posts', 'slug' => 'opening-day', 'date' => now()->subDay()->format('Y-m-d H:i:s')],
            ['title' => 'Opening day', 'content' => "We open on Saturday.\n\n## What to bring\n\nYourselves.", 'author' => $this->admin->id])->save();
        $url = (string) $post->uri();
        $this->actingAs($this->admin)->get($url)->assertSee('Build this page');
        $this->actingAs($this->admin)->postJson(route('builder.start', $post->id()))->assertOk();
        $page = $this->build($post);
        $this->assertSame(['text-picture', 'text-picture'], array_column($page->draft, 'type'), 'no hero: the post’s head has its title');
        $this->assertSame('We open on Saturday.', $page->draft[0]['fields']['body']);
        $this->act($post->id(), 'publish');
        $this->assertStringNotContainsString('<h1', (string) $this->act($post->id(), 'tab', 'outline')->json('page'), 'the post’s head has the h1');

        $html = $this->reader($url)->assertOk()->assertSee('<h1 class="post-head__title">Opening day</h1>', false)->assertSee('class="pb-post"', false)
            ->assertSee('What to bring')->assertSee('class="byline"', false)->getContent();
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringNotContainsString('prose--dropcap', $html);
        $this->assertFalse($this->act($post->id(), 'tab', 'page')->json('bar.blog'));
        $this->assertStringNotContainsString('Use it as the home page', (string) $this->act($post->id(), 'tab', 'page')->json('drawer'));
    }

    public function test_search_excerpts_and_reading_time_read_what_readers_see(): void
    {
        $about = $this->about();
        $page = $this->startAbout();
        $this->act($about->id(), 'set', ['id' => $page->draft[0]['id'], 'field' => 'lede', 'value' => 'Pandan chiffon every morning']);
        $this->reader('/search?q=pandan')->assertSee('0 results');
        $this->act($about->id(), 'publish');

        $this->reader('/search?q=pandan')->assertSee('1 result for')->assertSee('Pandan chiffon every morning');
        $this->reader('/search?q=writes+here')->assertSee('0 results', false);
        $this->assertStringContainsString('Pandan chiffon every morning', app(\App\Booklet\Site::class)->excerpt($this->about()));
    }

    public function test_every_section_draws_for_readers_and_while_building(): void
    {
        $builder = app(\App\PageBuilder\Builder::class);
        $types = $builder->types();
        $this->assertGreaterThanOrEqual(23, count($types));
        foreach (array_keys($types) as $type) {
            $section = $builder->make($type);
            foreach ([true, false] as $live) {
                $html = view('builder.sections', ['sections' => [$section], 'types' => $types, 'live' => $live, 'embedded' => false]
                    + app(\App\PageBuilder\PageBuilder::class)->blogContext(\Illuminate\Http\Request::create('/'), [$section]))->render();
                $this->assertStringContainsString('pb--'.$type, $html, $type);
                $this->assertSame($live, str_contains($html, 'data-pb-section'), $type);
            }
            $this->assertNotSame('', app(\App\PageBuilder\PageBuilder::class)->asText([$section]) ?: $type, $type);
        }

        // The new ones, as readers see them.
        $html = view('builder.sections', ['sections' => array_map(fn ($t) => $builder->make($t), ['banner', 'notice', 'divider', 'stats', 'steps', 'team', 'menu', 'hours']),
            'types' => $types, 'live' => false, 'embedded' => false])->render();
        foreach (['<h1 class="pb-title pb-title--big">Something worth stopping for</h1>', 'pb-notice__sticker', '<hr class="pb-divider pb-divider--line">',
            '<p class="pb-stat__number">40+</p>', '<span class="pb-step__n" aria-hidden="true">3</span>', 'pb-person__name', '<h3 class="pb-menu__group">Another group</h3>',
            '<span class="pb-menu__price">$5</span>', '<dt><span>Sunday</span></dt>', 'Your street'] as $bit) {
            $this->assertStringContainsString($bit, $html);
        }
        $this->assertStringNotContainsString('Choose a picture', $html, 'pickers only while building');
        $this->assertStringNotContainsString('Get directions', $html, 'a button with nowhere to go isn’t shown to readers');

        // A new thing on a menu joins the group it's added to.
        $menu = $builder->items($builder->make('menu'), 'items', 'add', 0);
        $this->assertSame(['A group', '', '', 'Another group', ''], array_column($menu['fields']['items'], 'group'));

        // Two more layouts to start from.
        $this->assertSame(['hero', 'menu', 'hours', 'quotes', 'cta'], array_column($builder->starter('shop'), 'type'));
        $this->assertSame(['hero', 'stats', 'team', 'steps'], array_column($builder->starter('team'), 'type'));
    }

    public function test_what_was_published_is_kept_to_look_at_and_put_back(): void
    {
        $about = $this->about();
        $page = $this->startAbout();
        $hero = $page->draft[0]['id'];
        foreach (['First version', 'Second version', 'Third version'] as $i => $title) {
            $this->travel(1)->minutes();
            $this->act($about->id(), 'set', ['id' => $hero, 'field' => 'title', 'value' => $title]);
            $this->act($about->id(), 'publish');
        }
        $versions = $this->build($about)->versions()->get();
        $this->assertCount(3, $versions);
        $this->assertSame('Third version', $versions[0]->sections[0]['fields']['title'], 'newest first');
        $this->assertSame($this->admin->id, $versions[0]->user_id);

        // In the Page tab: when, who, which is on the site; look at one; put one back.
        $drawer = $this->act($about->id(), 'tab', 'page')->json('drawer');
        $this->assertStringContainsString('Published before · 3', $drawer);
        $this->assertSame(1, substr_count($drawer, 'on the site now'));
        $this->assertStringContainsString('by '.e($this->admin->name), $drawer);
        $first = $versions[2];
        $this->actingAs($this->admin)->get(route('builder.preview', $about->id()).'?version='.$first->id)
            ->assertRedirect(route('preview', $about->id()).'?preview=1&version='.$first->id);
        $this->actingAs($this->admin)->get(route('preview', $about->id()).'?preview=1&version='.$first->id)
            ->assertSee('<h1 class="pb-title pb-title--big">First version</h1>', false)->assertDontSee('id="pb-editor"', false);
        $this->reader('/about?preview=1&version='.$first->id)->assertSee('<h1 class="pb-title pb-title--big">Third version</h1>', false)->assertDontSee('First version');

        $this->act($about->id(), 'restore', $first->id)->assertJsonPath('bar.status', 'changed');
        $this->assertSame('First version', $this->build($about)->draft[0]['fields']['title']);
        $this->reader('/about')->assertSee('Third version');
        $this->act($about->id(), 'undo');
        $this->assertSame('Third version', $this->build($about)->draft[0]['fields']['title'], 'Undo takes it back');
        $this->act($about->id(), 'restore', 999999)->assertJsonPath('stamp', 'That version isn’t kept any more.');

        // Only so many are kept.
        $build = $this->build($about);
        for ($i = 0; $i < \App\Models\PageBuildVersion::KEEP + 3; $i++) {
            app(\App\PageBuilder\PageBuilder::class)->goLive($build, $build->draft, now()->addMinutes($i + 10));
        }
        $this->assertSame(\App\Models\PageBuildVersion::KEEP, $build->versions()->count());

        // Scheduled ones say so.
        app(\App\PageBuilder\PageBuilder::class)->goLive($build, $build->draft, now()->addHour(), true);
        $this->assertStringContainsString('Went live as scheduled', (string) $this->act($about->id(), 'tab', 'page')->json('drawer'));
    }

    public function test_words_keep_bold_italic_and_links(): void
    {
        $about = $this->about();
        $page = $this->startAbout();
        $hero = $page->draft[0]['id'];
        $this->act($about->id(), 'set', ['id' => $hero, 'field' => 'lede', 'value' => 'Fresh **every morning**, *mostly*. See [the menu](/menu) or [write](hello@example.com). 2 \\* 3 = 6, <b>not html</b>'])->assertOk();
        $this->assertSame('Fresh **every morning**, *mostly*. See [the menu](/menu) or [write](mailto:hello@example.com). 2 \\* 3 = 6, <b>not html</b>', $this->build($about)->draft[0]['fields']['lede']);

        $this->assertSame(['field:lede' => 'The link on “anywhere” doesn’t go anywhere: use /about, https://example.com or an email address.'],
            $this->act($about->id(), 'set', ['id' => $hero, 'field' => 'lede', 'value' => 'Go [anywhere](javascript:alert)'])->assertStatus(422)->json('errors'));

        $this->act($about->id(), 'publish');
        $this->reader('/about')->assertSee('Fresh <strong>every morning</strong>, <em>mostly</em>. See <a href="/menu">the menu</a> or <a href="mailto:hello@example.com">write</a>. 2 * 3 = 6, &lt;b&gt;not html&lt;/b&gt;', false);
        $this->assertStringContainsString('Fresh every morning, mostly. See the menu or write. 2 * 3 = 6', app(\App\Booklet\Site::class)->excerpt($this->about(), 300));
        $this->reader('/search?q=every+morning')->assertSee('1 result for');

        // A link that isn't safe never becomes one, however it got there.
        $this->assertSame('a bad) link', \App\PageBuilder\Fields::format('a [bad](javascript:alert(1)) link'));
        $this->assertSame('<a href="https://example.com" rel="noopener">out</a>', \App\PageBuilder\Fields::format('[out](https://example.com)'));
    }

    public function test_shared_sections_change_everywhere_when_published(): void
    {
        $about = $this->about();
        $contact = Booklet::entries()->findBySlug('pages', 'contact');
        $this->startAbout();
        $this->act($about->id(), 'add', 'hours');
        $hours = collect($this->build($about)->draft)->firstWhere('type', 'hours')['id'];
        $this->act($about->id(), 'publish');

        // Shared: named, then on another page too.
        $this->assertSame(['share' => 'Give it a name, to find it by on other pages.'],
            $this->act($about->id(), 'share', ['id' => $hours, 'name' => ' '], ['section' => $hours])->assertStatus(422)->json('errors'));
        $this->act($about->id(), 'share', ['id' => $hours, 'name' => 'Our hours'], ['section' => $hours])
            ->assertJsonPath('stamp', 'Shared as “Our hours”. Add it to other pages from Add a section › Shared.');
        $this->act($about->id(), 'publish'); // on the site as the shared one now
        $shared = \App\Models\SharedSection::firstOrFail();
        $this->assertSame('hours', $shared->type);
        $this->assertNotNull($shared->published);
        $this->assertStringContainsString('Shared: “Our hours”', (string) $this->act($about->id(), 'select', $hours, ['section' => $hours])->json('drawer'));

        $this->actingAs($this->admin)->postJson(route('builder.start', $contact->id()))->assertOk();
        $this->assertStringContainsString('Our hours', (string) $this->act($contact->id(), 'adding', true, ['adding' => true])->json('drawer'));
        $this->act($contact->id(), 'add', 'shared:'.$shared->id);
        $there = collect($this->build($contact)->draft)->firstWhere('type', 'hours');
        $this->assertSame($shared->id, $there['shared']);
        $this->assertStringContainsString('data-pb-name="Opening hours · shared"', (string) $this->act($contact->id(), 'tab', 'outline')->json('page'));

        // Changed on one page: in both drafts at once; on the site when published (everywhere).
        $this->act($contact->id(), 'set', ['id' => $there['id'], 'field' => 'title', 'value' => 'Opening times']);
        $this->assertSame('Opening times', collect(app(\App\PageBuilder\PageBuilder::class)->sectionsOf($this->build($about)))->firstWhere('type', 'hours')['fields']['title']);
        $this->act($about->id(), 'tab', 'page')->assertJsonPath('bar.status', 'changed');
        $this->reader('/about')->assertSee('Come and see us')->assertDontSee('Opening times');
        $this->act($contact->id(), 'publish');
        $this->reader('/about')->assertSee('Opening times')->assertDontSee('Come and see us');
        $this->reader('/contact')->assertSee('Opening times');
        $this->act($about->id(), 'tab', 'page')->assertJsonPath('bar.status', 'live');

        // Undo puts the shared one back too.
        $this->travel(1)->minutes(); // a new Undo step, not more of the same typing
        $this->act($contact->id(), 'set', ['id' => $there['id'], 'field' => 'title', 'value' => 'Hours']);
        $this->act($contact->id(), 'undo');
        $this->assertSame('Opening times', $shared->fresh()->draft['fields']['title']);

        // A copy is the page's own; so is one made its own again.
        $this->act($contact->id(), 'copy', $there['id']);
        $this->assertSame(1, collect($this->build($contact)->draft)->filter(fn ($s) => isset($s['shared']))->count());
        $this->act($about->id(), 'unshare', $hours)->assertJsonPath('stamp', 'It’s this page’s own now: changes here stay here.');
        $this->act($about->id(), 'set', ['id' => $hours, 'field' => 'title', 'value' => 'About’s own hours']);
        $this->assertSame('Opening times', $shared->fresh()->draft['fields']['title']);

        // Forgotten only when no page uses it.
        $this->act($contact->id(), 'forget-shared', $shared->id)->assertJsonPath('stamp', 'It’s still on a page. Take it off the pages first.');
        $this->act($contact->id(), 'remove', $there['id']);
        $this->act($contact->id(), 'publish');
        $this->act($about->id(), 'publish');
        $this->act($contact->id(), 'forget-shared', $shared->id)->assertJsonPath('stamp', '“Our hours” is gone.');
        $this->assertSame(0, \App\Models\SharedSection::count());
        $this->reader('/about')->assertSee('About’s own hours');
    }

    public function test_checks_before_publishing(): void
    {
        $entry = Booklet::entries()->make(['collection' => 'pages', 'published' => false], ['title' => 'Spring offer', 'content' => ''])->save();
        $this->actingAs($this->admin)->postJson(route('builder.start', $entry->id()))->assertOk();
        $pages = app(\App\PageBuilder\PageBuilder::class);
        $build = $this->build($entry);
        $this->act($entry->id(), 'add', 'video');
        $this->act($entry->id(), 'add', 'gallery');
        $words = array_column($pages->checks($build->fresh()), 'words');
        foreach (['Hero: no picture yet (or choose No picture, under Where the picture goes).', 'Hero: the button “Get started” doesn’t go anywhere yet.',
            'Columns: still has its example words (“Why people choose it”).', 'Quotes: still has its example words (“Something a real person said about it.…”).',
            'Video: no video chosen yet.', 'Pictures: no pictures chosen yet.'] as $expected) {
            $this->assertContains($expected, $words);
        }
        $this->assertNotContains('Hero: still has its example words (“Spring offer”).', $words, 'its own title, not an example');

        // In the Page tab, each going to its section.
        $drawer = (string) $this->act($entry->id(), 'tab', 'page')->json('drawer');
        $this->assertStringContainsString('Before you publish · '.count($words).' to look at', $drawer);
        $hero = $build->fresh()->draft[0]['id'];
        $this->assertStringContainsString('data-do="select" data-payload="&quot;'.$hero.'&quot;"', $drawer);

        // Seen to: the list goes.
        $this->act($entry->id(), 'set', ['id' => $hero, 'field' => 'button.url', 'value' => '/menu']);
        $this->act($entry->id(), 'set', ['id' => $hero, 'field' => 'layout', 'value' => 'none']);
        $this->assertNotContains('Hero: the button “Get started” doesn’t go anywhere yet.', array_column($pages->checks($build->fresh()), 'words'));
        $this->assertNotContains('Hero: no picture yet (or choose No picture, under Where the picture goes).', array_column($pages->checks($build->fresh()), 'words'));
        $build->fresh()->put(['draft' => [app(\App\PageBuilder\Builder::class)->make('divider')]]);
        $this->assertSame([], $pages->checks($build->fresh()));
        $this->assertStringContainsString('Nothing to look at before publishing.', (string) $this->act($entry->id(), 'tab', 'page')->json('drawer'));
        $this->act($entry->id(), 'publish')->assertOk(); // never stopped
    }

    public function test_the_draft_is_shared_with_a_private_link(): void
    {
        $about = $this->about();
        $page = $this->startAbout();
        $this->act($about->id(), 'publish');
        $this->act($about->id(), 'set', ['id' => $page->draft[0]['id'], 'field' => 'title', 'value' => 'Who we are, draft']);

        $r = $this->act($about->id(), 'proof', ['label' => 'Aisha', 'days' => 3, 'notes' => '1'], ['tab' => 'page'])
            ->assertJsonPath('stamp', 'Link made. Copy it and send it to Aisha.');
        $url = $r->json('copy');
        $this->assertStringStartsWith(url('/proof/'), $url);
        $drawer = (string) $r->json('drawer');
        $this->assertStringContainsString('Aisha', $drawer);
        $this->assertStringContainsString('data-copy="'.e($url).'"', $drawer);

        // Whoever has it: the draft, with the notes, never the editor; readers still see what's published.
        $html = $this->reader(parse_url($url, PHP_URL_PATH))->assertOk()->assertSee('Who we are, draft')->assertSee('data-proof', false)
            ->assertDontSee('id="pb-editor"', false)->getContent();
        $this->assertStringContainsString('noindex', $html);
        $this->reader('/about')->assertDontSee('Who we are, draft');

        // Their notes show in the Page tab, to see to.
        $secret = basename(parse_url($url, PHP_URL_PATH));
        $this->app['auth']->forgetGuards();
        $this->post(route('proof.note', $secret), ['name' => 'Aisha', 'body' => 'Can the heading say “Who we are” only?'])->assertRedirect();
        $drawer = (string) $this->act($about->id(), 'tab', 'page', ['tab' => 'page'])->json('drawer');
        $this->assertStringContainsString('1 note to see to', $drawer);
        $this->assertStringContainsString('Can the heading say', $drawer);
        $note = \App\Models\ProofNote::firstOrFail();
        $this->act($about->id(), 'proof-note', $note->id, ['tab' => 'page']);
        $this->assertNotNull($note->fresh()->resolved_at);

        // Stopped: the link works no more.
        $proof = \App\Models\Proof::firstOrFail();
        $this->act($about->id(), 'proof-stop', $proof->id, ['tab' => 'page'])->assertJsonPath('stamp', 'That link works no more.');
        $this->reader(parse_url($url, PHP_URL_PATH))->assertNotFound();

        // The blog page has no links to share (it isn't one of the pages).
        $this->actingAs($this->admin)->getJson(route('builder.edit', 'blog'));
        $this->assertStringNotContainsString('Share the draft', (string) $this->act('blog', 'tab', 'page', ['tab' => 'page'])->json('drawer'));
    }

    public function test_shows_on_dragging_layouts_and_columns(): void
    {
        $about = $this->about();
        $page = $this->startAbout();
        $hero = $page->draft[0]['id'];

        // Shows on computers only, or phones only: hidden for the other (labelled while building).
        $this->act($about->id(), 'look', ['id' => $hero, 'key' => 'show', 'value' => 'phone']);
        $this->assertStringContainsString('pb-show--phone', (string) $this->act($about->id(), 'tab', 'outline')->json('page'));
        $this->act($about->id(), 'look', ['id' => $hero, 'key' => 'show', 'value' => 'tablet'])->assertJsonPath('stamp', 'That isn’t one of the choices.');

        // Start again from a layout Booklet comes with (its hero says the page's name); Undo takes it back.
        $drawer = (string) $this->act($about->id(), 'tab', 'page', ['tab' => 'page'])->json('drawer');
        $this->assertStringContainsString('A shop or café', $drawer);
        $this->act($about->id(), 'layout', 'starter:shop', ['tab' => 'page'])->assertJsonPath('stamp', 'Started again from that layout. (Undo takes it back.)');
        $this->assertSame(['hero', 'menu', 'hours', 'quotes', 'cta'], array_column($this->build($about)->draft, 'type'));
        $this->assertSame('About', $this->build($about)->draft[0]['fields']['title']);
        $this->act($about->id(), 'undo');
        $this->assertSame(['hero'], array_column($this->build($about)->draft, 'type'));
        $this->act($about->id(), 'layout', 'starter:nope')->assertJsonPath('stamp', 'That layout isn’t there any more.');

        // Moving to a place (what dragging on the page sends).
        $this->act($about->id(), 'add', 'free');
        $this->act($about->id(), 'move', ['id' => collect($this->build($about)->draft)->firstWhere('type', 'free')['id'], 'to' => 0]);
        $this->assertSame(['free', 'hero'], array_column($this->build($about)->draft, 'type'));

        // Saved as a layout, and another page started from it (new sections, the same words).
        $this->assertSame(['layout' => 'Give it a name, to choose it by.'], $this->act($about->id(), 'save-layout', ['name' => ''])->assertStatus(422)->json('errors'));
        $this->act($about->id(), 'save-layout', ['name' => 'Two columns and a hero'])->assertJsonPath('stamp', 'Saved as the layout “Two columns and a hero”. Start any page from it, under Start from a layout.');
        $layout = \App\Models\PageLayout::firstOrFail();
        $entry = Booklet::entries()->make(['collection' => 'pages', 'published' => false], ['title' => 'Spring offer', 'content' => ''])->save();
        $this->actingAs($this->admin)->postJson(route('builder.start', $entry->id()));
        $this->assertStringContainsString('Two columns and a hero', (string) $this->act($entry->id(), 'tab', 'page', ['tab' => 'page'])->json('drawer'));
        $this->act($entry->id(), 'layout', 'saved:'.$layout->id);
        $there = $this->build($entry)->draft;
        $this->assertSame(['free', 'hero'], array_column($there, 'type'));
        $this->assertNotSame($this->build($about)->draft[0]['id'], $there[0]['id']);
        $this->act($entry->id(), 'forget-layout', $layout->id)->assertJsonPath('stamp', 'The layout “Two columns and a hero” is gone. (Pages started from it stay as they are.)');

        // Columns of your own, as readers see them: two, wider on the left; an empty column part isn't drawn.
        $this->act($entry->id(), 'set', ['id' => $there[0]['id'], 'field' => 'widths', 'value' => 'left']);
        $this->act($entry->id(), 'set', ['id' => $there[0]['id'], 'field' => 'items.1.title', 'value' => '']);
        $this->act($entry->id(), 'publish');
        $this->reader('/spring-offer')->assertSee('pb-free--2 pb-free--left', false)->assertSee('On the left')->assertDontSee('On the right')
            ->assertSee('Each column has the same choices.');

        // The blog page doesn't start again from layouts.
        $this->actingAs($this->admin)->getJson(route('builder.edit', 'blog'));
        $this->assertStringNotContainsString('Start from a layout', (string) $this->act('blog', 'tab', 'page', ['tab' => 'page'])->json('drawer'));
    }

    public function test_stacy_writes_a_section_as_asked(): void
    {
        \App\Models\AiProvider::create(['service' => 'openrouter', 'enabled' => true, 'is_default' => true, 'api_key' => 'k', 'default_model' => 'deepseek/deepseek-chat']);
        $about = $this->about();
        $this->startAbout();
        $this->act($about->id(), 'add', 'faq');
        $faq = collect($this->build($about)->draft)->firstWhere('type', 'faq')['id'];
        $this->assertStringContainsString('Ask Stacy to write it', (string) $this->act($about->id(), 'select', $faq, ['section' => $faq])->json('drawer'));

        $reply = json_encode(['title' => 'Delivery questions', 'intro' => 'What people ask most.', 'items' => [
            ['question' => 'Do you deliver?', 'answer' => 'Yes, **every day** within [your area].'],
            ['question' => 'How much is it?', 'answer' => 'It costs [price].'],
            ['question' => 'Can I pick up?', 'answer' => 'Of course. See [the map](/contact).'],
        ]]);
        \Illuminate\Support\Facades\Http::fake(['openrouter.ai/*' => \Illuminate\Support\Facades\Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => "Here it is:\n".$reply]]]])]);

        $this->act($about->id(), 'stacy', ['id' => $faq, 'ask' => 'Questions about delivery'], ['section' => $faq])
            ->assertJsonPath('stamp', 'Stacy wrote it. Read it over and change anything that isn’t right (anything in [brackets] is yours to fill in). Undo takes it back.');
        $fields = collect($this->build($about)->draft)->firstWhere('type', 'faq')['fields'];
        $this->assertSame('Delivery questions', $fields['title']);
        $this->assertSame(['Do you deliver?', 'How much is it?', 'Can I pick up?'], array_column($fields['items'], 'question'));
        $this->assertSame('Of course. See [the map](/contact).', $fields['items'][2]['answer']);
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains((string) json_encode($r->data()), 'Questions about delivery'));
        $this->act($about->id(), 'undo');
        $this->assertSame('Questions', collect($this->build($about)->draft)->firstWhere('type', 'faq')['fields']['title']);

        // Asked for nothing: said under the box.
        $this->assertSame(['stacy' => 'Say what it should be about, and I’ll write it.'],
            $this->act($about->id(), 'stacy', ['id' => $faq, 'ask' => ' '], ['section' => $faq])->assertStatus(422)->json('errors'));
    }

    public function test_two_people_building_one_page_never_undo_each_other(): void
    {
        $about = $this->about();
        $page = $this->startAbout();
        $hero = $page->draft[0]['id'];
        $hana = User::factory()->create(['role' => 'editor', 'name' => 'Hana']);
        $post = fn (User $who, string $action, mixed $payload, ?int $rev) => $this->actingAs($who)->postJson(route('builder.act', $about->id()),
            ['action' => $action, 'payload' => $payload, 'state' => ['section' => $hero], 'rev' => $rev]);

        // Both open it: each sees the other, and which change it's at.
        $mine = $this->actingAs($this->admin)->getJson(route('builder.edit', $about->id()))->json();
        $hers = $this->actingAs($hana)->getJson(route('builder.edit', $about->id()))->json();
        $this->assertSame([$this->admin->name], $hers['bar']['others']);
        $this->assertSame(['rev' => $mine['rev'], 'others' => ['Hana']], $this->actingAs($this->admin)->postJson(route('builder.here', $about->id()))->json());

        // Hana changes it first. Mine, made on what I saw before, isn't done over hers:
        $rev = $post($hana, 'set', ['id' => $hero, 'field' => 'title', 'value' => 'Hana’s title'], $hers['rev'])->assertOk()->json('rev');
        $this->assertSame($mine['rev'] + 1, $rev);
        $stale = $post($this->admin, 'set', ['id' => $hero, 'field' => 'title', 'value' => 'My title'], $mine['rev'])->assertStatus(409);
        $this->assertSame('Hana changed this page a moment ago, so it’s brought up to date here. Make your change again.', $stale->json('stamp'));
        $this->assertTrue($stale->json('stale'));
        $this->assertStringContainsString('Hana’s title', (string) $stale->json('page'));
        $this->assertSame('Hana’s title', $this->build($about)->draft[0]['fields']['title']);

        // Made again, now it's up to date: done.
        $post($this->admin, 'set', ['id' => $hero, 'field' => 'title', 'value' => 'My title'], $stale->json('rev'))->assertOk();
        $this->assertSame('My title', $this->build($about)->draft[0]['fields']['title']);
        // Only looking is never too late; and my other window is named as mine.
        $post($hana, 'tab', 'page', $hers['rev'])->assertOk();
        $this->assertStringStartsWith('You changed it in another window', (string) $post($this->admin, 'set', ['id' => $hero, 'field' => 'lede', 'value' => 'x'], $mine['rev'])->json('stamp'));

        // Leaving: gone from the others' bar.
        $this->actingAs($hana)->post(route('builder.here', $about->id()), ['leaving' => 1])->assertOk();
        $this->assertSame([], $this->actingAs($this->admin)->postJson(route('builder.here', $about->id()))->json('others'));
    }

    public function test_a_pictures_description_and_focus_from_the_drawer(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $path = (new \App\Booklet\Support\AssetUploader('media'))->store(\Illuminate\Http\UploadedFile::fake()->image('cake.jpg', 1200, 800), 'blog')->path();
        $about = $this->about();
        $page = $this->startAbout();
        $hero = $page->draft[0]['id'];
        $this->act($about->id(), 'set', ['id' => $hero, 'field' => 'picture', 'value' => $path]);
        $this->act($about->id(), 'set', ['id' => $hero, 'field' => 'layout', 'value' => 'side']);
        $pages = app(\App\PageBuilder\PageBuilder::class);
        $this->assertContains('Hero: a picture has no description, for people who can’t see them (add one in Media).', array_column($pages->checks($this->build($about)), 'words'));

        // Under the chosen picture: where it's focused, and its description.
        $drawer = (string) $this->act($about->id(), 'select', $hero, ['section' => $hero])->json('drawer');
        $this->assertStringContainsString('data-focus="'.$path.'"', $drawer);
        $this->assertStringContainsString('data-alt="'.$path.'"', $drawer);
        $this->assertStringContainsString('Not described yet.', $drawer);

        $this->act($about->id(), 'alt', ['path' => $path, 'alt' => 'A slice of <b>pandan</b> cake'], ['section' => $hero])
            ->assertJsonPath('stamp', 'Description saved, for everywhere it’s used.');
        $asset = Booklet::assets()->find(['container' => 'media', 'path' => $path]);
        $this->assertSame('A slice of pandan cake', $asset->get('alt'), 'kept in Media, as words only');
        $this->act($about->id(), 'focus', ['path' => $path, 'x' => 30, 'y' => 70], ['section' => $hero])
            ->assertJsonPath('stamp', 'That spot stays in view, everywhere it’s used.');
        $this->assertSame('30-70-1', Booklet::assets()->find(['container' => 'media', 'path' => $path])->get('focus'));
        $page = (string) $this->act($about->id(), 'tab', 'outline')->json('page');
        $this->assertStringContainsString('object-position: 30% 70%', $page);
        $this->assertStringContainsString('alt="A slice of pandan cake"', $page);
        $this->assertNotContains('Hero: a picture has no description, for people who can’t see them (add one in Media).', array_column($pages->checks($this->build($about)->fresh()), 'words'));

        // Only people who can change Media can change them.
        $card = \App\Models\Role::create(['key' => 'no_media', 'name' => 'No media', 'tone' => 'sky', 'base' => 'editor', 'chapters' => ['pages' => 'publish'], 'media' => false, 'stacy' => false]);
        $nomedia = User::factory()->create(['role' => $card->key]);
        $this->act($about->id(), 'alt', ['path' => $path, 'alt' => 'Changed'], ['section' => $hero], $nomedia)->assertOk()->assertJsonPath('stamp', null);
        $this->assertSame('A slice of pandan cake', Booklet::assets()->find(['container' => 'media', 'path' => $path])->get('alt'));
    }

    public function test_redo_and_sending_a_section_to_another_page(): void
    {
        $about = $this->about();
        $contact = Booklet::entries()->findBySlug('pages', 'contact');
        $hero = $this->startAbout()->draft[0]['id'];

        // Redo brings back what Undo took, until the next change.
        $this->act($about->id(), 'add', 'faq');
        $this->act($about->id(), 'redo')->assertJsonPath('stamp', 'Nothing to redo.')->assertJsonPath('bar.redoable', false);
        $this->act($about->id(), 'undo')->assertJsonPath('bar.redoable', true);
        $this->assertCount(1, $this->build($about)->draft);
        $this->act($about->id(), 'redo')->assertJsonPath('bar.redoable', false)->assertJsonPath('bar.undoable', true);
        $this->assertSame(['hero', 'faq'], array_column($this->build($about)->draft, 'type'));
        $this->act($about->id(), 'undo');
        $this->act($about->id(), 'copy', $hero);
        $this->act($about->id(), 'redo')->assertJsonPath('stamp', 'Nothing to redo.');
        $this->assertSame(['hero', 'hero'], array_column($this->build($about)->draft, 'type'));
        $this->act($about->id(), 'undo');

        // Sending: only to pages being built (the blog page too, once it is).
        $drawer = (string) $this->act($about->id(), 'select', $hero, ['section' => $hero])->json('drawer');
        $this->assertStringNotContainsString('Send a copy to another page', $drawer);
        $this->actingAs($this->admin)->postJson(route('builder.start', $contact->id()))->assertOk();
        $this->actingAs($this->admin)->getJson(route('builder.edit', 'blog'))->assertOk();
        $drawer = (string) $this->act($about->id(), 'select', $hero, ['section' => $hero])->json('drawer');
        $this->assertStringContainsString('Send a copy to another page', $drawer);
        $this->assertStringContainsString('<option value="'.$contact->id().'"', $drawer);
        $this->assertStringContainsString('<option value="blog"', $drawer);

        $this->assertSame(['send' => 'Choose a page to send it to.'],
            $this->act($about->id(), 'send', ['id' => $hero, 'to' => ''], ['section' => $hero])->assertStatus(422)->json('errors'));
        $before = count($this->build($contact)->draft);
        $rev = (int) $this->build($contact)->rev;
        $this->act($about->id(), 'set', ['id' => $hero, 'field' => 'title', 'value' => 'Sent along']);
        $this->act($about->id(), 'send', ['id' => $hero, 'to' => (string) $contact->id()], ['section' => $hero])->assertOk()
            ->assertJsonPath('stamp', 'A copy is at the end of “Contact” (a draft change there, until it’s published).')
            ->assertJsonPath('open', url('/contact').'?edit=1');
        $there = $this->build($contact)->draft;
        $this->assertCount($before + 1, $there);
        $this->assertSame('Sent along', end($there)['fields']['title']);
        $this->assertNotSame($hero, end($there)['id']);
        $this->assertGreaterThan($rev, (int) $this->build($contact)->rev, 'anyone building it there is brought up to date');
        $this->reader('/contact')->assertDontSee('Sent along');
        $this->act($contact->id(), 'undo'); // its own Undo takes it back there
        $this->assertCount($before, $this->build($contact)->draft);

        // A kind the other page can't have, or nowhere it may go.
        $this->assertSame([], app(\App\PageBuilder\PageBuilder::class)->sendTargets($this->build($about), 'blog-posts'));
        $this->act($about->id(), 'send', ['id' => $hero, 'to' => 'nowhere'], ['section' => $hero])->assertStatus(422);
    }

    public function test_the_slip_and_who_can_build(): void
    {
        $about = $this->about();
        $this->startAbout();
        $this->act($about->id(), 'add', 'faq');
        $this->assertSame('', app(\App\PageBuilder\PageBuilder::class)->slip('about'), 'nothing until it’s published');
        $this->act($about->id(), 'publish');
        $this->assertStringContainsString('pb-embed', app(\App\PageBuilder\PageBuilder::class)->slip('about'));

        $this->app['auth']->forgetGuards();
        $this->getJson(route('builder.edit', $about->id()))->assertUnauthorized();
        $this->act($about->id(), 'publish', null, [], User::factory()->create(['role' => 'writer']))->assertNotFound();
        $this->act('nothing-here', 'publish')->assertNotFound();
        $this->actingAs($this->admin)->getJson(route('builder.edit', Booklet::entries()->findBySlug('pages', 'contact')->id()))->assertNotFound();
    }

    public function test_a_theme_that_draws_pages_itself_isnt_built_here(): void
    {
        config(['booklet.themes_path' => storage_path('framework/testing/themes')]);
        File::ensureDirectoryExists(\App\Themes\ThemeManager::path().'/ownpages/views/site');
        File::put(\App\Themes\ThemeManager::path().'/ownpages/theme.json', json_encode(['key' => 'ownpages', 'name' => 'Own pages', 'version' => '1.0']));
        File::put(\App\Themes\ThemeManager::path().'/ownpages/views/site/page.blade.php', '<p>A page of its own</p>');
        \App\Themes\ThemeManager::forget();
        \App\Support\Look::save(\App\Support\Look::clean(['theme' => 'ownpages']));

        $this->actingAs($this->admin)->get('/about')->assertDontSee('pb-editor', false);
        $this->actingAs($this->admin)->postJson(route('builder.start', $this->about()->id()))->assertNotFound();
    }

    public function test_the_old_insert_stands_aside_and_what_it_built_comes_across(): void
    {
        $about = $this->about();
        // As the insert kept them: a page built from About (in its place), one from scratch, and the blog.
        $section = fn (string $title) => ['id' => 'abcd'.substr(md5($title), 0, 6), 'type' => 'hero', 'fields' => ['title' => $title], 'look' => []];
        \App\Models\InsertRecord::create(['insert' => 'page-builder', 'kind' => 'pages', 'slug' => 'about', 'data' => [
            'title' => 'About', 'draft' => [$section('Built about')], 'published' => [$section('Built about')], 'home' => false,
            'replaces' => ['entry' => $about->id(), 'title' => 'About', 'address' => 'about', 'done' => now()->toIso8601String()],
        ]]);
        $about->setAttribute('published', false)->save();
        \App\Models\InsertRecord::create(['insert' => 'page-builder', 'kind' => 'pages', 'slug' => 'offer', 'data' => [
            'title' => 'Offer', 'draft' => [$section('Our offer')], 'published' => [$section('Our offer')], 'home' => true,
        ]]);
        \App\Models\InsertRecord::create(['insert' => 'page-builder', 'kind' => 'pages', 'slug' => null, 'data' => [
            'title' => 'The blog', 'blog' => true, 'draft' => [$section('Blog draft')], 'published' => null,
        ]]);

        $this->artisan('booklet:builder-import')->expectsOutputToContain('“About”: on the site at /about.')
            ->expectsOutputToContain('“Offer”: on the site at /offer (your home page).')->expectsOutputToContain('The blog page: its arrangement is a draft.')->assertSuccessful();
        $this->reader('/about')->assertOk()->assertSee('Built about');
        $this->reader('/')->assertOk()->assertSee('Our offer');
        $this->assertNotNull(PageBuild::blog());
        $this->artisan('booklet:builder-import')->expectsOutputToContain('Everything was here already.');

        $this->assertArrayHasKey('page-builder', \App\Inserts\InsertManager::BUILT_IN);
    }
}
