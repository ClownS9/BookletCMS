<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Forms as FormsPage;
use App\Livewire\Admin\Inbox as InboxPage;
use App\Livewire\Site\MadeForm;
use App\Models\User;
use App\Notifications\NewMessage;
use App\Support\Forms;
use App\Support\Inbox;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Forms of your own: made in Comments › Forms, put on a page with the page
 * builder, answered by readers, and read in the Inbox.
 */
class FormsTest extends TestCase
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

    /** A form with one of each kind of question, made and saved on the Forms page. */
    protected function rsvp(): \App\Booklet\Data\Form
    {
        $page = Livewire::actingAs($this->admin)->test(FormsPage::class)
            ->assertSee('No forms yet')
            ->set('newName', '')->call('create')->assertHasErrors(['newName'])
            ->set('newName', 'Wedding RSVP')->call('create')->assertHasNoErrors()
            ->assertSet('chosen', 'wedding-rsvp')->assertSet('name', 'Wedding RSVP')
            ->assertSet('fields.0.key', 'name')->assertSet('fields.1.type', 'email');

        // A list needs two choices; a question needs its words.
        $page->call('addField', 'choice')->set('fields.3.label', 'Coming?')->set('fields.3.options', "Yes")
            ->call('save')->assertHasErrors(['fields.3.options']);
        $page->set('fields.3.options', "Yes, gladly\nSadly not")->set('fields.3.required', true)
            ->call('addField', 'choices')->set('fields.4.label', 'Which meals?')->set('fields.4.options', "Lunch\nDinner\nDessert")
            ->call('addField', 'number')->set('fields.5.label', 'How many guests?')
            ->call('addField', 'date')
            ->call('addField', 'tick')->set('fields.7.label', 'I’ll bring a smile')->set('fields.7.required', true)
            ->call('addField', 'text')
            ->call('save')->assertHasErrors(['fields.8.label']);
        $page->call('removeField', 8)->call('moveField', 2, 1)
            ->set('thanks', 'See you there!')->set('button', 'Reply')
            ->call('save')->assertHasNoErrors();

        $form = Forms::find('wedding-rsvp');
        $this->assertSame(['name', 'email', 'coming', 'message', 'which_meals', 'how_many_guests', 'which_day', 'ill_bring_a_smile'], array_column(Forms::fields($form), 'key'));
        $this->assertSame(['Yes, gladly', 'Sadly not'], Forms::fields($form)[2]['options']);
        $this->assertSame('See you there!', $form->get('thanks'));

        return $form;
    }

    public function test_a_form_is_made_put_on_a_page_answered_and_read(): void
    {
        Notification::fake();
        $form = $this->rsvp();

        // On a page, with the page builder.
        $about = Booklet::entries()->findBySlug('pages', 'about');
        $this->actingAs($this->admin)->postJson(route('builder.start', $about->id()))->assertOk();
        $this->actingAs($this->admin)->postJson(route('builder.act', $about->id()), ['action' => 'add', 'payload' => 'form']);
        $id = collect(\App\Models\PageBuild::forEntry($about)->draft)->firstWhere('type', 'form')['id'];
        $drawer = (string) $this->actingAs($this->admin)->postJson(route('builder.act', $about->id()), ['action' => 'select', 'payload' => $id, 'state' => ['section' => $id]])->json('drawer');
        $this->assertStringContainsString('<option value="wedding-rsvp"', $drawer);
        $this->actingAs($this->admin)->postJson(route('builder.act', $about->id()), ['action' => 'set', 'payload' => ['id' => $id, 'field' => 'form', 'value' => 'nope']])->assertStatus(422);
        $this->actingAs($this->admin)->postJson(route('builder.act', $about->id()), ['action' => 'set', 'payload' => ['id' => $id, 'field' => 'form', 'value' => 'wedding-rsvp']])->assertOk();
        $this->actingAs($this->admin)->postJson(route('builder.act', $about->id()), ['action' => 'publish'])->assertOk();

        $this->app['auth']->forgetGuards();
        Booklet::flush();
        $this->get('/about')->assertOk()->assertSee('Coming?')->assertSee('Which meals?')->assertSee('Reply');

        // Answered: checked first, then kept, and the team hears.
        $send = Livewire::test(MadeForm::class, ['handle' => 'wedding-rsvp', 'page' => 'about'])
            ->set('values.email', 'not an email')->set('values.coming', 'Maybe')->set('values.which_meals', ['Supper'])
            ->call('send')->assertHasErrors(['values.name', 'values.email', 'values.coming', 'values.which_meals.0', 'values.ill_bring_a_smile']);
        $this->travel(5)->seconds();
        $send->set('values.name', 'Siti Aminah')->set('values.email', 'SITI@Example.com')->set('values.coming', 'Yes, gladly')
            ->set('values.which_meals', ['Lunch', 'Dessert'])->set('values.how_many_guests', '2')->set('values.which_day', '2026-12-05')
            ->set('values.ill_bring_a_smile', true)->set('values.message', '=SUM(A1) just kidding')
            ->call('send')->assertHasNoErrors()->assertSet('sent', true)->assertSee('See you there!');

        $answer = Inbox::all('wedding-rsvp')->first();
        $this->assertSame('siti@example.com', $answer->get('email'));
        $this->assertSame(['Lunch', 'Dessert'], $answer->get('which_meals'));
        $this->assertSame('Yes', $answer->get('ill_bring_a_smile'));
        $this->assertSame('new', Inbox::status($answer));
        $this->assertSame(['Siti Aminah', 'siti@example.com'], Inbox::sender($answer));
        $this->assertSame('Lunch, Dessert', Inbox::extras($answer)['Which meals?']);
        Notification::assertSentTo($this->admin, NewMessage::class, function (NewMessage $n) {
            $mail = $n->toMail($this->admin);

            return str_contains($mail->subject, 'Wedding RSVP') && str_contains($mail->greeting, 'An answer to “Wedding RSVP”');
        });

        // In the Inbox, with the contact form's, and on its own.
        Livewire::actingAs($this->admin)->test(InboxPage::class)
            ->assertSee('Siti Aminah')->assertSee('Wedding RSVP')
            ->set('from', 'wedding-rsvp')->assertSee('Download its answers (CSV)')
            ->call('pick', $answer->id())->assertSee('Which meals?')->assertSee('Lunch, Dessert')
            ->set('from', 'nope')->assertSet('from', '');

        // As a spreadsheet: a cell that looks like a formula stays words.
        $csv = (string) $this->actingAs($this->admin)->get(route('admin.forms.csv', 'wedding-rsvp'))->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $this->assertStringContainsString('Sent,"Your name",Email,Coming?', str_replace("\u{FEFF}", '', $csv));
        $this->assertStringContainsString('"Lunch, Dessert"', $csv);
        $this->assertStringContainsString("'=SUM(A1) just kidding", $csv);

        // A bot fills in the hidden box: all seems well, nothing is kept.
        Livewire::test(MadeForm::class, ['handle' => 'wedding-rsvp'])->set('website', 'spam.example')->call('send')->assertSet('sent', true);
        $this->assertCount(1, Inbox::all('wedding-rsvp'));
    }

    public function test_closed_forms_writers_and_deleting(): void
    {
        $form = $this->rsvp();

        // Closed: readers see so, and sending is refused.
        Livewire::actingAs($this->admin)->test(FormsPage::class, ['chosen' => 'wedding-rsvp'])->toggle('open')->call('save');
        $this->assertFalse(Forms::open(Forms::find('wedding-rsvp')));
        Livewire::test(MadeForm::class, ['handle' => 'wedding-rsvp'])->assertSee('Wedding RSVP is closed for now.');
        Livewire::test(MadeForm::class, ['handle' => 'wedding-rsvp'])->call('send')->assertForbidden();

        // Writers don't make forms.
        $this->actingAs(User::factory()->create(['role' => 'author']))->get(route('admin.forms'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'author']))->get(route('admin.forms.csv', 'wedding-rsvp'))->assertForbidden();

        // Deleted, with its answers.
        Forms::receive(Forms::find('wedding-rsvp'), ['name' => 'Ali', 'email' => 'ali@example.com']);
        $this->assertCount(1, Inbox::all('wedding-rsvp'));
        Livewire::actingAs($this->admin)->test(FormsPage::class, ['chosen' => 'wedding-rsvp'])->call('delete')->assertSet('chosen', null);
        $this->assertNull(Forms::find('wedding-rsvp'));
        $this->assertCount(0, Booklet::submissions()->whereForm('wedding-rsvp'));
        $this->get(route('admin.forms.csv', 'wedding-rsvp'))->assertNotFound();

        // The contact form is never one of "your forms".
        $this->assertNull(Forms::find(Inbox::FORM));
        $this->assertArrayNotHasKey(Inbox::FORM, Forms::choices());
    }
}
