<?php

namespace App\Livewire\Admin;

use App\Inserts\Insert;
use App\Inserts\InsertInstaller;
use App\Inserts\InsertManager;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Settings › Inserts: the shelf of extras in inserts/. Add them (a .zip, a
 * link, a GitHub repository), switch them on and off, set them up, update
 * them, see what went wrong. Admins only, and adding or updating one asks
 * for their password (an insert is code that runs with the whole site's
 * keys), unless they gave it in the last few hours.
 */
#[Layout('components.layouts.admin')]
#[Title('Inserts')]
class Inserts extends Component
{
    use \App\Livewire\Concerns\BrowsesCatalog;
    use Stamps;

    #[Url(as: 'insert', except: '')]
    public string $chosen = '';

    public array $values = [];

    /** Adding one: the form for a link or a file. */
    #[Url(as: 'add', except: false)]
    public bool $adding = false;

    /** One that's been fetched and unpacked to one side, waiting for a yes. */
    #[Url(as: 'look', except: '')]
    public string $staged = '';

    public string $source = '';

    public string $password = '';

    public function mount(InsertManager $inserts): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        if ($this->staged !== '' && ! app(InsertInstaller::class)->staged($this->staged)) {
            $this->staged = '';
        }
        if ($this->chosen === '' || ! $inserts->find($this->chosen)) {
            $this->chosen = (string) $inserts->all()->keys()->first();
        }
        $this->loadValues();
    }

    protected function manager(): InsertManager
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return app(InsertManager::class);
    }

    protected function current(): ?Insert
    {
        return $this->manager()->find($this->chosen);
    }

    protected function loadValues(): void
    {
        $this->values = $this->current()?->settings() ?? [];
        $this->resetErrorBag();
    }

    protected function installer(): InsertInstaller
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return app(InsertInstaller::class);
    }

    public function pick(string $key): void
    {
        abort_unless($this->manager()->find($key) !== null, 404);
        $this->dropStaged();
        $this->adding = false;
        $this->chosen = $key;
        $this->loadValues();
    }

    // Adding and updating -----------------------------------------------------------------

    public function startAdding(): void
    {
        $this->dropStaged();
        $this->adding = true;
        $this->source = '';
        $this->resetErrorBag();
    }

    /** Fetch from a link or GitHub, unpack to one side, and show what it is. */
    public function fetch(): void
    {
        $this->resetErrorBag();
        $this->validate(['source' => ['required', 'string', 'max:500']], ['source.required' => 'Paste a link to a .zip, or a GitHub repository.']);
        if ($why = $this->installer()->blocked()) {
            $this->addError('source', $why);

            return;
        }

        try {
            $this->staged = $this->installer()->stageFrom($this->installer()->parseSource($this->source));
            $this->adding = false;
            $this->password = '';
        } catch (\RuntimeException $e) {
            $this->addError('source', $e->getMessage());
        }
    }

    /** Fetch the newer version of the chosen insert and show it, ready to say yes to. */
    public function lookAtUpdate(): void
    {
        $insert = $this->current();
        if (! $insert?->source()) {
            return;
        }
        try {
            $this->staged = $this->installer()->stageFrom($insert->source());
            $this->password = '';
            $this->resetErrorBag();
        } catch (\RuntimeException $e) {
            $this->stamp('The update couldn’t be fetched: '.\Illuminate\Support\Str::limit($e->getMessage(), 160));
        }
    }

    public function checkNow(): void
    {
        $insert = $this->current();
        if (! $insert?->source()) {
            return;
        }
        $found = $this->installer()->check($insert->key);
        $this->manager()->forget();
        $reached = \Illuminate\Support\Facades\DB::table('inserts')->where('key', $insert->key)->value('latest_version') !== null;
        $this->stamp(match (true) {
            isset($found[$insert->key]) => 'Version '.$found[$insert->key].' is ready.',
            $reached => 'It’s up to date.',
            default => 'Booklet couldn’t reach where it came from. It’ll try again tomorrow.',
        });
    }

    public function needsPassword(): bool
    {
        return time() - (int) session('auth.password_confirmed_at', 0) > (int) config('auth.password_timeout', 10800);
    }

    /** Yes: move it into inserts/. */
    public function install(): void
    {
        $meta = $this->installer()->staged($this->staged);
        if (! $meta) {
            $this->staged = '';
            $this->stamp('That isn’t waiting any more. Add it again.');

            return;
        }

        $this->resetErrorBag();
        if ($this->needsPassword()) {
            $limit = 'insert-password:'.request()->user()?->id;
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($limit, 5)) {
                $this->addError('password', 'Too many tries. Wait a minute, then try again.');

                return;
            }
            \Illuminate\Support\Facades\RateLimiter::hit($limit, 60);
            $this->validate(['password' => ['required', 'current_password']], [
                'password.required' => 'Type your password to say yes.',
                'password.current_password' => 'That isn’t your password.',
            ]);
            \Illuminate\Support\Facades\RateLimiter::clear($limit);
            session()->put('auth.password_confirmed_at', time());
        }
        $this->password = '';

        try {
            $done = $this->installer()->install($this->staged);
        } catch (\RuntimeException $e) {
            $this->addError('install', $e->getMessage());

            return;
        }

        $cmp = $done['updated'] ? version_compare($done['version'], (string) $done['from']) : null;
        ActivityLog::record($done['updated'] ? 'updated the insert' : 'added the insert', 'settings', 'settings', 'inserts',
            $done['name'].($done['updated'] ? ' ('.$done['from'].' → '.$done['version'].')' : ' '.$done['version']));
        $this->stamp(match (true) {
            $cmp === null => $done['name'].' is on the shelf. Switch it on when you’re ready.',
            $cmp > 0 => $done['name'].' is updated to '.$done['version'].'. If anything’s wrong, Undo update puts '.$done['from'].' back.',
            default => $done['name'].' is replaced with the copy you added. The one before is kept, if you want it back.',
        }, afterRedirect: true);
        $this->redirectRoute('admin.settings.inserts', ['insert' => $done['key']], navigate: false);
    }

    public function cancelStaged(): void
    {
        $this->dropStaged();
        $this->resetErrorBag();
    }

    protected function dropStaged(): void
    {
        if ($this->staged !== '') {
            $this->installer()->discard($this->staged);
            $this->staged = '';
        }
        $this->password = '';
    }

    public function undoUpdate(): void
    {
        $insert = $this->current();
        if (! $insert) {
            return;
        }
        try {
            $version = $this->installer()->undo($insert->key);
        } catch (\RuntimeException $e) {
            $this->stamp($e->getMessage());

            return;
        }
        ActivityLog::record('went back to an earlier copy of the insert', 'settings', 'settings', 'inserts', $insert->name().' '.$version);
        $this->stamp($insert->name().' is back to version '.$version.'.', afterRedirect: true);
        $this->redirectRoute('admin.settings.inserts', ['insert' => $insert->key], navigate: false);
    }

    public function toggle(): void
    {
        $insert = $this->current();
        if (! $insert) {
            return;
        }

        try {
            if ($insert->enabled()) {
                $this->manager()->disable($insert->key);
                ActivityLog::record('took out the insert', 'settings', 'settings', 'inserts', $insert->name());
                $this->stamp($insert->name().' is off. What it kept is still here.', afterRedirect: true);
            } else {
                $this->manager()->enable($insert->key);
                ActivityLog::record('tucked in the insert', 'settings', 'settings', 'inserts', $insert->name());
                $this->stamp($insert->name().' is on.', afterRedirect: true);
            }
        } catch (Throwable $e) {
            report($e);
            $this->manager()->noteError($insert->key, $e);
            $this->stamp('It couldn’t be switched on: '.\Illuminate\Support\Str::limit($e->getMessage(), 140), afterRedirect: true);
        }

        $this->manager()->forget();
        // A full load, so its page, flag and pieces appear (or go) everywhere.
        $this->redirectRoute('admin.settings.inserts', ['insert' => $insert->key], navigate: false);
    }

    /** Torn out: try loading it again (after fixing the problem). */
    public function retry(): void
    {
        $insert = $this->current();
        if ($insert) {
            $this->manager()->forget();
            try {
                $this->manager()->enable($insert->key);
                $this->stamp('Tucked back in. If it breaks again, it comes out again.', afterRedirect: true);
            } catch (Throwable $e) {
                $this->manager()->noteError($insert->key, $e);
                $this->stamp('Still not working: '.\Illuminate\Support\Str::limit($e->getMessage(), 140), afterRedirect: true);
            }
            $this->redirectRoute('admin.settings.inserts', ['insert' => $insert->key], navigate: false);
        }
    }

    public function saveSettings(): void
    {
        $insert = $this->current();
        if (! $insert) {
            return;
        }

        $rules = $names = [];
        foreach ($insert->settingsSchema() as $handle => $f) {
            $rules['values.'.$handle] = match ($f['type']) {
                'toggle' => ['boolean'],
                'number' => array_filter(['nullable', 'numeric', $f['min'] !== null ? 'min:'.$f['min'] : null, $f['max'] !== null ? 'max:'.$f['max'] : null]),
                'email' => ['nullable', 'email', 'max:190'],
                'url' => ['nullable', 'url', 'max:500'],
                'select' => ['nullable', 'in:'.implode(',', array_map('strval', array_keys($f['options'])))],
                'textarea' => ['nullable', 'string', 'max:5000'],
                default => ['nullable', 'string', 'max:500'],
            };
            $names['values.'.$handle] = mb_strtolower($f['label']);
        }
        $this->validate($rules, [], $names);

        $clean = [];
        foreach ($insert->settingsSchema() as $handle => $f) {
            $v = $this->values[$handle] ?? $f['default'];
            $clean[$handle] = match ($f['type']) {
                'toggle' => (bool) $v,
                'number' => $v === '' || $v === null ? $f['default'] : $v + 0,
                default => is_string($v) ? trim($v) : $v,
            };
        }

        $this->manager()->saveSettings($insert->key, $clean);
        ActivityLog::record('changed the settings of the insert', 'settings', 'settings', 'inserts', $insert->name());
        $this->loadValues();
        $this->stamp('Saved.');
    }

    public function toggleValue(string $handle): void
    {
        $this->values[$handle] = ! ($this->values[$handle] ?? false);
    }

    /**
     * Remove it: switched off, everything it kept deleted, and (when Booklet
     * may write there) its folder taken off the shelf too.
     */
    public function forgetInsert(bool $data = false): void
    {
        $insert = $this->current();
        if (! $insert) {
            return;
        }
        $this->manager()->forgetInsert($insert->key, $data);
        $gone = false;
        if ($data && ! $this->installer()->blocked()) {
            $this->installer()->remove($insert->key);
            $gone = true;
        }
        ActivityLog::record($data ? 'removed the insert and what it kept:' : 'reset the insert', 'settings', 'settings', 'inserts', $insert->name());
        $this->stamp(! $data ? 'Reset.' : ($gone
            ? $insert->name().' is removed, with everything it kept.'
            : 'Removed, with everything it kept. Delete its folder in inserts/ to take it off the shelf.'), afterRedirect: true);
        $this->redirectRoute('admin.settings.inserts', $gone ? [] : ['insert' => $insert->key], navigate: false);
    }

    public function render()
    {
        $manager = $this->manager();
        $insert = $this->current();

        $installer = $this->installer();

        return view('livewire.admin.inserts', [
            'inserts' => $manager->all(),
            'blocked' => $installer->blocked(),
            'look' => $this->staged !== '' ? $installer->staged($this->staged) : null,
            'previous' => $insert ? $installer->previousVersion($insert->key) : null,
            'insert' => $insert,
            'safeMode' => $manager->safeMode(),
            'records' => $insert ? \App\Models\InsertRecord::where('insert', $insert->key)->count() : 0,
            'folder' => str_replace(base_path().'/', '', $manager->path()),
        ]);
    }
}
