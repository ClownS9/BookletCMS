<?php

namespace Tests\Feature;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\PageBuild;
use App\Models\User;
use App\PageBuilder\Builder;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * More ways a section can look: sticker colours, a picture's shade, a
 * rounded box, text size, a line, coming into view, and a name for links.
 * Only what makes sense is offered (a shade only over a picture).
 */
class PageBuilderLooksTest extends TestCase
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

    protected function about(): Entry
    {
        return Booklet::entries()->findBySlug('pages', 'about') ?? $this->fail('no About page');
    }

    protected function act(string $action, mixed $payload = null, array $state = []): TestResponse
    {
        return $this->actingAs($this->admin)->postJson(route('builder.act', $this->about()->id()), ['action' => $action, 'payload' => $payload, 'state' => $state]);
    }

    protected function reader(string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);

        return $this->get($url);
    }

    public function test_the_new_looks_are_offered_set_and_drawn(): void
    {
        $this->actingAs($this->admin)->postJson(route('builder.start', $this->about()->id()))->assertOk();
        $hero = PageBuild::forEntry($this->about())->draft[0]['id'];

        // On paper: no shape or shade to choose; every colour, the text size, a line and a name for links are.
        $drawer = (string) $this->act('select', $hero)->assertOk()->json('drawer');
        $this->assertStringContainsString('Sunny', $drawer);
        $this->assertStringContainsString('Text size', $drawer);
        $this->assertStringContainsString('As it scrolls into view', $drawer);
        $this->assertStringContainsString('data-look="anchor"', $drawer);
        $this->assertStringNotContainsString('Over the picture', $drawer);
        $this->assertStringNotContainsString('A rounded box', $drawer);
        $this->assertStringContainsString('data-keep="look"', $drawer);

        foreach (['background' => 'mint', 'frame' => 'card', 'size' => 'large', 'line' => 'below', 'motion' => 'rise', 'width' => 'full', 'space' => 'none'] as $key => $value) {
            $this->act('look', ['id' => $hero, 'key' => $key, 'value' => $value], ['section' => $hero])->assertOk();
        }
        $drawer = (string) $this->act('select', $hero)->json('drawer');
        $this->assertStringContainsString('A rounded box', $drawer, 'a coloured section can be a box');

        // A name for links: tidied, and refused when there's nothing usable in it.
        $this->act('look', ['id' => $hero, 'key' => 'anchor', 'value' => '#Our Prices!'], ['section' => $hero])->assertOk();
        $refused = $this->act('look', ['id' => $hero, 'key' => 'anchor', 'value' => '123'], ['section' => $hero])->assertStatus(422);
        $this->assertStringStartsWith('Use letters, numbers and dashes', $refused->json('errors')['look:anchor']);
        $this->assertStringContainsString('value="123"', (string) $refused->json('drawer'), 'what was typed stays, to fix');
        // Not a choice there is.
        $this->act('look', ['id' => $hero, 'key' => 'size', 'value' => 'huge'], ['section' => $hero]);

        $look = PageBuild::forEntry($this->about())->draft[0]['look'];
        $this->assertSame(['mint', 'card', 'large', 'below', 'rise', 'full', 'none', 'our-prices'],
            [$look['background'], $look['frame'], $look['size'], $look['line'], $look['motion'], $look['width'], $look['space'], $look['anchor']]);

        // Readers: the classes, the name to link to; it moves only for readers (not while building).
        $this->act('publish')->assertOk();
        $html = $this->reader('/about')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/class="pb pb--hero pb-bg--mint pb-space--none pb-align--\w+ pb-width--full pb-frame--card pb-size--large pb-line--below pb-motion--rise"/', $html);
        $this->assertStringContainsString('<span class="pb-anchor" id="our-prices" aria-hidden="true"></span>', $html);
        $editing = (string) $this->actingAs($this->admin)->getJson(route('builder.edit', $this->about()->id()))->assertOk()->json('page');
        $this->assertStringContainsString('pb-size--large', $editing);
        $this->assertStringNotContainsString('pb-motion--rise', $editing);

        // A shade is offered (and drawn) only over a picture; a rounded box only on a colour.
        $this->act('look', ['id' => $hero, 'key' => 'background', 'value' => 'none'], ['section' => $hero]);
        $html = (string) $this->actingAs($this->admin)->getJson(route('builder.edit', $this->about()->id()))->json('page');
        $this->assertStringContainsString('pb-bg--none', $html);
        $this->assertStringNotContainsString('pb-frame--card', $html);
    }

    public function test_old_sections_get_every_look_and_odd_ones_are_put_right(): void
    {
        $builder = app(\App\PageBuilder\PageBuilder::class)->builder;
        $this->assertInstanceOf(Builder::class, $builder);
        [$s] = $builder->clean([['id' => 'abcd1234', 'type' => 'hero', 'fields' => [], 'look' => ['background' => 'soft', 'space' => 'roomy', 'anchor' => '<script>', 'size' => ['x']]]]);
        $this->assertSame('soft', $s['look']['background']);
        $this->assertSame('roomy', $s['look']['space']);
        $this->assertSame('script', $s['look']['anchor']);
        $this->assertSame('normal', $s['look']['size']);
        $this->assertSame('band', $s['look']['frame']);
        $this->assertSame('none', $s['look']['motion']);
        $this->assertSame('', $builder->anchor('9 lives'));
        $this->assertTrue($builder->lookApplies('shade', ['background' => 'picture']));
        $this->assertFalse($builder->lookApplies('shade', ['background' => 'soft']));
    }
}
