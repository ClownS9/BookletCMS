<?php

namespace App\Livewire\Admin;

use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Livewire\Concerns\PageTitle;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Models\ApiKey;
use App\Support\ContentApi;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings › Content API: turn the read-only API on, make and switch off
 * keys, choose which other websites' pages may read it, and examples to
 * copy. A new key is shown once, then only its last four letters. Admins only.
 */
#[Layout('components.layouts.admin')]
class ContentApiSettings extends Component
{
    use PageTitle;
    use Stamps;

    /** The most keys switched on at once. */
    public const MAX_KEYS = 20;

    /** Named so it can't clash with Livewire's own $wire.on (an event listener, which a checkbox would read as "on"). */
    public bool $enabled = false;

    public bool $openToAll = false;

    public string $origins = '';

    /** What the next key is for: "the phone app", "Aminah's site". */
    public string $name = '';

    /** The key just made: shown once, then gone. */
    public ?string $fresh = null;

    public function mount(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $settings = ContentApi::settings();
        $this->enabled = $settings['on'];
        $this->openToAll = $settings['open'];
        $this->origins = implode("\n", $settings['origins']);
    }

    public function save(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->validate(['origins' => ['nullable', 'string', 'max:2000']]);
        $origins = ContentApi::parseOrigins($this->origins);
        $before = ContentApi::settings();
        ContentApi::saveSettings(['on' => $this->enabled, 'open' => $this->openToAll, 'origins' => $origins]);
        $this->origins = implode("\n", $origins);

        if ($before['on'] !== $this->enabled) {
            ActivityLog::record($this->enabled ? 'turned on the content API' : 'turned off the content API', 'settings', 'settings', 'content_api', 'Content API');
        }
        if ($before['open'] !== $this->openToAll && $this->openToAll) {
            ActivityLog::record('opened the content API to everyone', 'settings', 'settings', 'content_api', 'Content API');
        }
        $this->stamp('Saved');
    }

    /** The switches save as soon as they're flipped. */
    public function updatedEnabled(): void
    {
        $this->save();
    }

    public function updatedOpenToAll(): void
    {
        $this->save();
    }

    public function make(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->validate(['name' => ['required', 'string', 'max:80']], ['name.required' => 'Say what it’s for, so you know which to switch off later.'], ['name' => 'what it’s for']);

        if (ApiKey::whereNull('revoked_at')->count() >= self::MAX_KEYS) {
            $this->addError('name', 'There are '.self::MAX_KEYS.' keys already. Switch off one you don’t use first.');

            return;
        }

        [$key, $plain] = ApiKey::issue($this->name, request()->user());
        ActivityLog::record('made a content API key', 'settings', 'settings', 'content_api', 'Content API', ['for' => $key->name]);
        $this->fresh = $plain;
        $this->reset('name');

        if (! $this->enabled) {
            $this->enabled = true;
            $this->save();
        }
    }

    public function forget(): void
    {
        $this->fresh = null;
    }

    public function revoke(int $id): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $key = ApiKey::whereNull('revoked_at')->find($id);
        if (! $key) {
            return;
        }
        $key->forceFill(['revoked_at' => now()])->save();
        ActivityLog::record('switched off a content API key', 'settings', 'settings', 'content_api', 'Content API', ['for' => $key->name]);
        $this->stamp('“'.$key->name.'” switched off. Anything using it stops getting answers now.');
    }

    public function render()
    {
        $site = app(Site::class);
        $post = $site->posts()->first();
        $collection = Booklet::collections()->all()->first(fn ($c) => $c->dated())?->handle() ?? 'posts';

        return $this->titled(view('livewire.admin.content-api', [
            'keys' => ApiKey::with('creator')->latest('id')->get(),
            'base' => ContentApi::base(),
            'collection' => $collection,
            'sampleId' => $post ? (string) $post->id() : '{id}',
            'perMinute' => ContentApi::PER_MINUTE,
        ]), 'Content API');
    }
}
