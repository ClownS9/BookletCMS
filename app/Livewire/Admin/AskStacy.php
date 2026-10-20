<?php

namespace App\Livewire\Admin;

use App\Booklet\Facades\Booklet;
use App\Livewire\Concerns\TalksToStacy;
use App\Stacy\Chat;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ask Stacy: the conversation page. Type or talk; Stacy looks things up
 * straight away and proposes changes, which only happen after a "Yes".
 *
 * Worked out when asked (#[Computed], from TalksToStacy):
 *
 * @property-read bool $enabled
 * @property-read array|null $brain
 * @property-read \Illuminate\Support\Collection<int, \App\Models\StacyMessage> $messages
 * @property-read array $voice
 */
#[Layout('components.layouts.admin')]
#[Title('Ask Stacy')]
class AskStacy extends Component
{
    use TalksToStacy;

    /** Arriving from Search with a question. */
    #[Url(except: '')]
    public string $q = '';

    public function mount(): void
    {
        $this->conversationId = Chat::latest(request()->user())?->id;

        if ($this->q !== '') {
            $this->draft = $this->q;
        }
    }

    public function openConversation(int $id): void
    {
        if ($conversation = Chat::find(request()->user(), $id)) {
            $this->conversationId = $conversation->id;
            $this->draft = '';
            unset($this->messages);
        }
    }

    public function deleteConversation(int $id): void
    {
        if ($conversation = Chat::find(request()->user(), $id)) {
            $conversation->messages()->delete();
            $conversation->delete();

            if ($this->conversationId === $id) {
                $this->conversationId = null;
            }
            unset($this->messages);
        }
    }

    /**
     * Arriving from Search: ask the question once the page has drawn.
     */
    public function askFromSearch(): void
    {
        if ($this->q !== '') {
            $question = $this->q;
            $this->q = '';
            $this->ask($question);
        }
    }

    public function render()
    {
        $drafts = Booklet::entries()->all()->filter(fn ($e) => ! $e->published())->count();

        return view('livewire.admin.ask-stacy', [
            'earlier' => Chat::history(request()->user()),
            'suggestions' => array_values(array_filter([
                ['Brief me on today', 'var(--sun)'],
                ['What needs attention in my book?', 'var(--coral)'],
                ['Show me my drafts', 'var(--sky)'],
                $drafts > 0 && in_array(request()->user()->level(), ['admin', 'editor'], true) ? ['Schedule my newest draft for tomorrow at 9am', 'var(--mint)'] : null,
                ['Write alt text for pictures that have none', 'var(--lilac)'],
                ['Start a draft about our opening hours', 'var(--rose)'],
            ])),
        ]);
    }
}
