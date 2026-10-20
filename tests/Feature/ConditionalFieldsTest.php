<?php

namespace Tests\Feature;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Collections;
use App\Livewire\Admin\EntryEditor;
use App\Models\User;
use App\Support\Conditions;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Show-this-field-when (Statamic's conditional fields).
 */
class ConditionalFieldsTest extends TestCase
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
        return Booklet::entries()->findBySlug('pages', 'about');
    }

    protected function pageFields(array $fields): void
    {
        $bp = Booklet::blueprints()->findIn('collections.pages', 'page');
        foreach ($fields as $handle => $config) {
            $bp->addField($handle, $config);
        }
        $bp->save();
        Booklet::flush();
    }

    public function test_rules_read_the_way_statamic_writes_them(): void
    {
        $values = ['has_button' => true, 'kind' => 'video', 'price' => '', 'tags' => ['kopi', 'kuih'], 'count' => 5, 'title' => 'Pandan chiffon'];

        $this->assertTrue(Conditions::passes(['if' => ['has_button' => true]], $values));
        $this->assertFalse(Conditions::passes(['if' => ['has_button' => false]], $values));
        $this->assertTrue(Conditions::passes(['if' => ['kind' => 'equals video']], $values));
        $this->assertTrue(Conditions::passes(['show_when' => ['kind' => 'video']], $values));
        $this->assertFalse(Conditions::passes(['if' => ['kind' => 'not video']], $values));
        $this->assertFalse(Conditions::passes(['if' => ['price' => 'not empty']], $values));
        $this->assertTrue(Conditions::passes(['if' => ['price' => 'empty']], $values));
        $this->assertTrue(Conditions::passes(['if' => ['price' => null]], $values));
        $this->assertFalse(Conditions::passes(['unless' => ['kind' => 'video']], $values));
        $this->assertTrue(Conditions::passes(['if_any' => ['price' => 'not empty', 'has_button' => true]], $values));
        $this->assertFalse(Conditions::passes(['if' => ['price' => 'not empty', 'has_button' => true]], $values));
        $this->assertTrue(Conditions::passes(['if' => ['tags' => 'contains kuih']], $values));
        $this->assertTrue(Conditions::passes(['if' => ['tags' => 'contains_any cake, kopi']], $values));
        $this->assertTrue(Conditions::passes(['if' => ['count' => '> 3', 'title' => 'contains chiffon']], $values));
        $this->assertFalse(Conditions::passes(['if' => ['count' => '<= 3']], $values));
        $this->assertTrue(Conditions::passes(['hide_when_any' => ['missing' => 'not empty']], $values));
        $this->assertTrue(Conditions::passes(['type' => 'text'], $values)); // no rules: shown
    }

    public function test_plain_words_become_a_rule(): void
    {
        $fields = [
            'has_button' => ['display' => 'Has button', 'type' => 'toggle', 'options' => []],
            'kind' => ['display' => 'Kind', 'type' => 'select', 'options' => ['video' => 'Video', 'photo_story' => 'Photo story']],
            'price' => ['display' => 'Price', 'type' => 'text', 'options' => []],
        ];
        $rule = fn (string $words) => Conditions::fromWords($words, $fields);

        $this->assertSame(['mode' => 'if', 'field' => 'has_button', 'op' => 'on', 'value' => ''], $rule('only when Has button is on'));
        $this->assertSame('off', $rule('when has button is turned off')['op']);
        $this->assertSame('off', $rule('when has button isn’t on')['op']);
        $this->assertSame(['mode' => 'unless', 'field' => 'kind', 'op' => 'is', 'value' => 'video'], $rule('hide it while Kind is video'));
        $this->assertSame(['mode' => 'if', 'field' => 'kind', 'op' => 'isnt', 'value' => 'photo_story'], $rule('when kind is not a photo story'));
        $this->assertSame('filled', $rule('when the price is filled in')['op']);
        $this->assertSame('empty', $rule('only if price is empty')['op']);
        $this->assertSame(['mode' => 'if', 'field' => 'price', 'op' => 'contains', 'value' => 'free'], $rule('when price contains "free"'));
        $this->assertNull($rule('when it is sunny'));

        $this->assertSame('Shown when Kind is “Photo story”', Conditions::describe(['if' => ['kind' => 'equals photo_story']], $fields));
        $this->assertSame('Hidden when Has button is off', Conditions::describe(['unless' => ['has_button' => false]], $fields));
    }

    public function test_the_editor_shows_and_keeps_fields_by_their_rules(): void
    {
        $this->pageFields([
            'has_button' => ['type' => 'toggle', 'display' => 'Has button'],
            'button_link' => ['type' => 'link', 'display' => 'Button link', 'validate' => ['required'], 'if' => ['has_button' => true]],
            'kind' => ['type' => 'select', 'display' => 'Kind', 'options' => ['photo' => 'Photo', 'video' => 'Video']],
            'video_url' => ['type' => 'link', 'display' => 'Video address', 'if' => ['kind' => 'equals video']],
        ]);

        $editor = Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $this->about()->id()])
            ->assertDontSee('Button link')
            ->assertDontSee('Video address')
            ->assertSeeHtml('wire:model.live="values.kind"') // others hang on it, so it updates at once
            ->call('save')->assertHasNoErrors();             // hidden and required: not asked for

        $editor->call('toggle', 'has_button')
            ->assertSee('Button link')
            ->call('save')->assertHasErrors('values.button_link')
            ->set('values.button_link', 'https://kopi.example/menu')
            ->set('values.kind', 'video')->assertSee('Video address')
            ->set('values.video_url', 'https://youtu.be/abcdefghijk')
            ->call('save')->assertHasNoErrors();

        $about = Booklet::entries()->find($this->about()->id());
        $this->assertSame('https://kopi.example/menu', $about->get('button_link'));
        $this->assertSame('https://youtu.be/abcdefghijk', $about->get('video_url'));

        // Switched off: the field goes, and so does what was in it.
        $editor->call('toggle', 'has_button')->assertDontSee('Button link')
            ->set('values.kind', 'photo')
            ->call('save')->assertHasNoErrors();
        $about = Booklet::entries()->find($this->about()->id());
        $this->assertNull($about->get('button_link'));
        $this->assertNull($about->get('video_url'));
    }

    public function test_always_save_keeps_a_hidden_value(): void
    {
        $this->pageFields([
            'featured' => ['type' => 'toggle', 'display' => 'Featured'],
            'badge' => ['type' => 'text', 'display' => 'Badge', 'if' => ['featured' => true], 'always_save' => true],
        ]);
        $this->about()->set('badge', 'New')->save();

        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $this->about()->id()])
            ->assertDontSee('Badge')->call('save')->assertHasNoErrors();
        $this->assertSame('New', Booklet::entries()->find($this->about()->id())->get('badge'));
    }

    public function test_the_builder_sets_a_rule_by_picking_or_in_words(): void
    {
        $page = Livewire::actingAs($this->admin)->test(Collections::class)->call('pick', 'pages')
            ->call('addField', 'toggle')->call('addField', 'link')->call('addField', 'select');
        $fields = $page->get('fields');
        [$toggle, $link, $select] = [count($fields) - 3, count($fields) - 2, count($fields) - 1];
        $page->set("fields.$toggle.display", 'Has button')->set("fields.$toggle.handle", 'has_button')
            ->set("fields.$link.display", 'Button link')->set("fields.$link.handle", 'button_link')
            ->set("fields.$select.display", 'Kind')->set("fields.$select.handle", 'kind')->set("fields.$select.options", 'Photo, Video')
            ->assertSee('Show only when…')
            ->set("fields.$link.when_open", true)
            ->set("fields.$link.when.field", 'has_button')
            ->assertSee('is on')
            ->set("fields.$link.when.op", 'on')
            ->assertSee('Shown when Has button is on')
            ->call('save')->assertHasNoErrors();

        $bp = Booklet::blueprints()->findIn('collections.pages', 'page');
        $this->assertSame(['has_button' => true], $bp->field('button_link')['if']);

        // In words, on the select's neighbour.
        $page->set("fields.$toggle.when_open", true)
            ->set("fields.$toggle.when_words", 'hide this while Kind is video')
            ->call('whenFromWords', $toggle)
            ->assertHasNoErrors()
            ->set("fields.$toggle.when_words", 'when it rains')
            ->call('whenFromWords', $toggle)
            ->assertHasErrors("fields.$toggle.when_words")
            ->call('save');
        $this->assertSame(['kind' => 'equals video'], Booklet::blueprints()->findIn('collections.pages', 'page')->field('has_button')['unless']);

        // "Always show" takes it away.
        $page->call('clearWhen', $link)->call('save');
        $this->assertArrayNotHasKey('if', Booklet::blueprints()->findIn('collections.pages', 'page')->field('button_link'));
    }

    public function test_a_richer_rule_written_in_the_file_is_kept(): void
    {
        $this->pageFields([
            'a' => ['type' => 'toggle', 'display' => 'A'],
            'b' => ['type' => 'toggle', 'display' => 'B'],
            'c' => ['type' => 'text', 'display' => 'C', 'if_any' => ['a' => true, 'b' => true]],
        ]);

        Livewire::actingAs($this->admin)->test(Collections::class)->call('pick', 'pages')
            ->assertSee('Shown only sometimes')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(['a' => true, 'b' => true], Booklet::blueprints()->findIn('collections.pages', 'page')->field('c')['if_any']);
    }

    public function test_readers_dont_see_rows_a_rule_switches_off(): void
    {
        $this->pageFields([
            'show_hours' => ['type' => 'toggle', 'display' => 'Show hours'],
            'hours' => ['type' => 'grid', 'display' => 'Opening hours', 'always_save' => true, 'if' => ['show_hours' => true],
                'fields' => [['handle' => 'day', 'field' => ['type' => 'text', 'display' => 'Day']]]],
        ]);
        $this->about()->set('hours', [['id' => 'a', 'day' => 'Saturday']])->save();
        Booklet::flush();

        $this->assertStringNotContainsString('Opening hours', $this->get('/about')->getContent());
        $this->about()->set('show_hours', true)->save();
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);
        \App\Support\PageCache::flush();
        $this->assertStringContainsString('Opening hours', $this->get('/about')->getContent());
    }
}
