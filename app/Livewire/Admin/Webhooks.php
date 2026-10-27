<?php

namespace App\Livewire\Admin;

use App\Booklet\Facades\Booklet;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Models\Webhook;
use App\Support\Webhooks as Hooks;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Settings › Webhooks: "when this happens, call this address". Start from
 * what it's for (a static host's rebuild, Slack, Discord, Zapier or Make),
 * choose what sets it off, send a test, and see what was sent. Admins only.
 */
#[Layout('components.layouts.admin')]
#[Title('Webhooks')]
class Webhooks extends Component
{
    use Stamps;

    /** The webhook being looked at, or "new". */
    #[Url(as: 'hook', except: null)]
    public ?string $chosen = null;

    public string $name = '';

    public string $url = '';

    public string $format = 'json';

    /** @var list<string> */
    public array $events = [];

    public bool $allCollections = true;

    /** @var list<string> */
    public array $collections = [];

    public bool $active = true;

    /** The secret is shown once asked for. */
    #[Locked]
    public bool $showSecret = false;

    /** What the last test said. */
    #[Locked]
    public ?array $tested = null;

    /** Where a new one starts: [name, events, format, hint]. */
    public const STARTS = [
        'rebuild' => ['Rebuild my static site', Hooks::SITE_CHANGES, 'json', 'Netlify, Vercel, Cloudflare Pages: paste the “build hook” or “deploy hook” address.'],
        'slack' => ['Tell our Slack channel', ['entry.published', 'comment.received', 'message.received'], 'slack', 'In Slack: Apps › Incoming Webhooks › Add to a channel, then paste the address it gives you.'],
        'discord' => ['Tell our Discord channel', ['entry.published', 'comment.received', 'message.received'], 'discord', 'In Discord: the channel’s settings › Integrations › Webhooks › New Webhook › Copy Webhook URL.'],
        'zapier' => ['Zapier, Make or my own code', ['entry.published'], 'json', 'In Zapier choose “Webhooks by Zapier › Catch Hook”; in Make, “Webhooks › Custom webhook”. Paste the address they give you.'],
    ];

    public function mount(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        if ($this->chosen && $this->chosen !== 'new' && ! $this->load((int) $this->chosen)) {
            $this->chosen = null;
        }
        if (! $this->chosen && ($first = Webhook::query()->orderBy('name')->first())) {
            $this->load($first->id);
        }
        if ($this->chosen === 'new') {
            $this->start('zapier');
        }
    }

    public function start(string $kind): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        [$name, $events, $format] = self::STARTS[$kind] ?? self::STARTS['zapier'];
        $this->chosen = 'new';
        $this->name = __($name);
        $this->url = '';
        $this->format = $format;
        $this->events = $events;
        $this->allCollections = true;
        $this->collections = [];
        $this->active = true;
        $this->showSecret = false;
        $this->tested = null;
        $this->resetValidation();
    }

    public function pick(int $id): void
    {
        $this->load($id);
    }

    protected function load(int $id): bool
    {
        $hook = Webhook::find($id);
        if (! $hook) {
            return false;
        }
        $this->chosen = (string) $hook->id;
        $this->name = $hook->name;
        $this->url = $hook->url;
        $this->format = $hook->format;
        $this->events = array_values((array) $hook->events);
        $this->allCollections = ! $hook->collections;
        $this->collections = array_values((array) $hook->collections);
        $this->active = $hook->active;
        $this->showSecret = false;
        $this->tested = null;
        $this->resetValidation();

        return true;
    }

    /** Pasting a Slack or Discord address picks the matching message format. */
    public function updatedUrl(): void
    {
        $guess = Hooks::guessFormat(trim($this->url));
        if ($guess !== 'json') {
            $this->format = $guess;
        }
    }

    /** Switching one on or off saves straight away (a new one waits for Save). */
    public function updatedActive(): void
    {
        if ($hook = $this->hook()) {
            $hook->forceFill(['active' => $this->active])->save();
            ActivityLog::record($this->active ? 'switched on a webhook' : 'switched off a webhook', 'settings', 'settings', 'webhooks', 'Webhooks', ['for' => $hook->name]);
            $this->stamp($this->active ? 'Switched on.' : 'Switched off. Nothing is sent until it’s on again.');
        }
    }

    public function save(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->name = trim($this->name);
        $this->url = trim($this->url);
        $handles = Booklet::collections()->all()->map(fn ($c) => $c->handle())->values()->all();
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'url' => ['required', 'string', 'max:2000', 'url:http,https'],
            'format' => ['required', Rule::in(array_keys(Hooks::FORMATS))],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(Hooks::EVENTS))],
            'collections' => ['array', Rule::requiredIf(! $this->allCollections)],
            'collections.*' => [Rule::in($handles)],
        ], [
            'events.required' => __('Choose at least one thing that sets it off.'),
            'collections.required' => __('Choose at least one chapter, or “Every chapter”.'),
            'url.url' => __('That doesn’t look like a web address. It starts with https://'),
        ], ['name' => __('name'), 'url' => __('address')]);

        if (! Hooks::allowed($this->url, $why)) {
            $this->addError('url', __('Booklet can’t call that address: :why.', ['why' => __($why ?? 'it isn’t a web address')]));

            return;
        }

        $values = [
            'name' => $this->name, 'url' => $this->url, 'format' => $this->format,
            'events' => array_values(array_intersect(array_keys(Hooks::EVENTS), $this->events)),
            'collections' => $this->allCollections ? null : $this->collections,
            'active' => $this->active,
        ];

        if ($hook = $this->hook()) {
            $hook->forceFill($values)->save();
            ActivityLog::record('changed a webhook', 'settings', 'settings', 'webhooks', 'Webhooks', ['for' => $hook->name]);
            $this->stamp('Saved.');

            return;
        }

        if (Webhook::count() >= Hooks::MAX_HOOKS) {
            $this->addError('name', __('That’s :count webhooks already. Delete one you don’t use first.', ['count' => Hooks::MAX_HOOKS]));

            return;
        }
        $hook = Webhook::create($values + ['secret' => Hooks::newSecret(), 'created_by' => request()->user()->id]);
        ActivityLog::record('added a webhook', 'settings', 'settings', 'webhooks', 'Webhooks', ['for' => $hook->name]);
        $this->load($hook->id);
        $this->stamp('Added. Send a test to see that it works.');
    }

    /** Call it now with a "ping", and show what came back. */
    public function test(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $hook = $this->hook();
        if (! $hook) {
            return;
        }
        $d = Hooks::test($hook);
        $this->tested = ['ok' => $d->status === 'sent', 'code' => $d->response_code, 'note' => (string) $d->response_note];
    }

    /** Try a delivery again now (one that failed, or is waiting to be tried again). */
    public function resend(int $id): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $hook = $this->hook();
        $d = $hook?->deliveries()->whereKey($id)->first();
        if (! $d || $d->status === 'sent') {
            return;
        }
        $d->forceFill(['status' => 'pending', 'attempts' => 0, 'due_at' => now()])->save();
        $this->stamp(Hooks::send($d) ? 'Sent.' : 'It didn’t go through this time either.');
    }

    public function revealSecret(): void
    {
        $this->showSecret = true;
    }

    public function newSecret(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        if ($hook = $this->hook()) {
            $hook->forceFill(['secret' => Hooks::newSecret()])->save();
            ActivityLog::record('made a new webhook secret', 'settings', 'settings', 'webhooks', 'Webhooks', ['for' => $hook->name]);
            $this->showSecret = true;
            $this->stamp('A new secret. Update it wherever the signature is checked.');
        }
    }

    public function delete(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        if ($hook = $this->hook()) {
            $name = $hook->name;
            $hook->delete();
            ActivityLog::record('deleted a webhook', 'settings', 'settings', 'webhooks', 'Webhooks', ['for' => $name]);
            $this->chosen = null;
            $next = Webhook::query()->orderBy('name')->first();
            $next ? $this->load($next->id) : $this->reset('name', 'url', 'events', 'collections');
            $this->stamp('Deleted.');
        }
    }

    protected function hook(): ?Webhook
    {
        return $this->chosen && $this->chosen !== 'new' ? Webhook::find((int) $this->chosen) : null;
    }

    public function render()
    {
        $hook = $this->hook();

        return view('livewire.admin.webhooks', [
            'hooks' => Webhook::query()->orderBy('name')->get(),
            'hook' => $hook,
            'deliveries' => $hook ? $hook->deliveries()->latest('id')->limit(15)->get() : collect(),
            'waiting' => $hook ? $hook->deliveries()->where('status', 'pending')->count() : 0,
            'eventList' => Hooks::EVENTS,
            'formats' => Hooks::FORMATS,
            'starts' => self::STARTS,
            'chapters' => Booklet::collections()->all()->map(fn ($c) => ['handle' => $c->handle(), 'title' => $c->title()])->values(),
            'secret' => $hook && $this->showSecret ? $hook->secret : null,
            'sample' => json_encode(Hooks::body($this->format, $this->sample()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** What a call looks like, for the right page. */
    protected function sample(): array
    {
        $site = app(\App\Booklet\Site::class);
        $entry = $site->posts()->first();
        $data = $entry ? Hooks::entryPayload($entry) : ['entry' => ['id' => '…', 'title' => 'Pandan chiffon, again', 'collection' => 'posts', 'status' => 'published', 'url' => url('/blog/pandan-chiffon')]];

        return ['event' => 'entry.published', 'happened_at' => now()->toIso8601String(), 'site' => ['name' => $site->name(), 'url' => $site->absolute(url('/'))]] + $data;
    }
}
