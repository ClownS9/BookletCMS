<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\ProofCopies;
use App\Models\Proof;
use App\Models\ProofNote;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proof copies: a private link to a draft for someone without an account,
 * and the notes they leave coming back to the editor.
 */
class ProofCopiesTest extends TestCase
{
    use RefreshDatabase;

    protected User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->editor = User::factory()->create(['role' => 'editor', 'name' => 'Nur']);
    }

    protected function draft(?User $author = null): \App\Booklet\Data\Entry
    {
        return Booklet::entries()->make(['collection' => 'posts', 'slug' => 'kuih-lapis', 'published' => false],
            ['title' => 'Kuih lapis, nine layers', 'content' => 'Steam each layer for five minutes.', 'author' => ($author ?? $this->editor)->id])->save();
    }

    /** @return array{0: Proof, 1: string} */
    protected function link(string $entryId, int $days = 7, bool $notes = true): array
    {
        Livewire::actingAs($this->editor)->test(ProofCopies::class, ['entryId' => $entryId])
            ->call('$set', 'making', true)
            ->set('label', 'Aminah')->set('days', $days)->set('notes', $notes)
            ->call('make')->assertDispatched('proof-made');

        $proof = Proof::latest('id')->firstOrFail();
        $secret = basename(parse_url((string) $proof->url(), PHP_URL_PATH));

        return [$proof, $secret];
    }

    public function test_someone_without_an_account_can_read_the_draft(): void
    {
        $entry = $this->draft();
        [$proof, $secret] = $this->link($entry->id());

        // Only the secret's fingerprint is looked up; the secret itself is kept encrypted.
        $this->assertSame(hash('sha256', $secret), $proof->token_hash);
        $this->assertStringNotContainsString($secret, (string) $proof->getRawOriginal('token'));

        auth()->logout();
        $response = $this->get('/proof/'.$secret)
            ->assertOk()
            ->assertSee('Proof copy')
            ->assertSee('Kuih lapis, nine layers')
            ->assertSee('Steam each layer')
            ->assertSee('Shared by Nur for Aminah')
            ->assertSee('data-proof-open', false)
            ->assertDontSee('<h3>Leave a note</h3>', false) // no public comment form on a proof copy
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->assertSame(1, $proof->fresh()->opens);
        $this->assertSame(0, DB::table('page_stats')->count(), 'not counted as a visit');

        // The draft itself is still hidden from the public site.
        $this->get('/blog/kuih-lapis')->assertNotFound();
    }

    public function test_closed_links_show_a_kind_page(): void
    {
        $entry = $this->draft();
        [$proof, $secret] = $this->link($entry->id(), days: 3);

        $this->get('/proof/'.str_repeat('a', 40))->assertNotFound()->assertSee('This link has closed');

        $this->travel(4)->days();
        $this->get('/proof/'.$secret)->assertNotFound()->assertSee('This link has closed');
        $this->travelBack();

        Livewire::actingAs($this->editor)->test(ProofCopies::class, ['entryId' => $entry->id()])->call('stop', $proof->id);
        auth()->logout();
        $this->get('/proof/'.$secret)->assertNotFound()->assertDontSee('Kuih lapis');
    }

    public function test_notes_come_back_to_the_editor(): void
    {
        $entry = $this->draft();
        [, $secret] = $this->link($entry->id());
        auth()->logout();

        $this->post('/proof/'.$secret.'/notes', ['name' => 'Aminah', 'quote' => 'five   minutes', 'body' => 'Maybe say six for a big tin?'])
            ->assertRedirect(route('proof.show', $secret).'#proof-notes');
        $this->post('/proof/'.$secret.'/notes', ['name' => '', 'body' => ''])->assertSessionHasErrors(['name', 'body']);
        $this->post('/proof/'.$secret.'/notes', ['name' => 'Bot', 'body' => 'Buy now', 'website' => 'http://spam.example'])->assertRedirect();

        $this->assertSame(1, ProofNote::count());
        $this->assertSame('five minutes', ProofNote::first()->quote);
        $this->get('/proof/'.$secret)->assertSee('Maybe say six for a big tin?')->assertSee('Left so far');

        $screen = Livewire::actingAs($this->editor)->test(ProofCopies::class, ['entryId' => $entry->id()])
            ->assertSee('Maybe say six for a big tin?')
            ->assertSee('five minutes')
            ->call('toggleDone', ProofNote::first()->id);
        $this->assertNotNull(ProofNote::first()->resolved_at);
        $screen->assertSee('1 seen to');
    }

    public function test_a_link_without_notes_takes_none(): void
    {
        $entry = $this->draft();
        [, $secret] = $this->link($entry->id(), notes: false);
        auth()->logout();

        $this->get('/proof/'.$secret)->assertOk()->assertDontSee('data-proof-open', false)->assertDontSee('proof-notes__form', false);
        $this->post('/proof/'.$secret.'/notes', ['name' => 'Aminah', 'body' => 'Hello'])->assertNotFound();
    }

    public function test_writers_share_only_their_own_entries(): void
    {
        $writer = User::factory()->create(['role' => 'writer']);
        $theirs = $this->draft($this->editor);

        Livewire::actingAs($writer)->test(ProofCopies::class, ['entryId' => $theirs->id()])->assertForbidden();

        $own = Booklet::entries()->make(['collection' => 'posts', 'slug' => 'mine', 'published' => false], ['title' => 'Mine', 'author' => $writer->id])->save();
        Livewire::actingAs($writer)->test(ProofCopies::class, ['entryId' => $own->id()])
            ->call('$set', 'making', true)->call('make')->assertHasNoErrors();
        $this->assertSame(1, Proof::where('entry_id', $own->id())->count());
    }

    public function test_search_engines_are_kept_out(): void
    {
        $this->get('/robots.txt')->assertSee('Disallow: /proof');
    }
}
