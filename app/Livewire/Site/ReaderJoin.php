<?php

namespace App\Livewire\Site;

use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\Reader;
use App\Support\Readers;
use App\Support\ReaderAccounts;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Making a reader's account: name, email, a password and the site's own
 * questions. Then an email to confirm the address. Whether they may join at
 * all is the site's (open, ask, invitation only, nobody new); an invitation
 * from the book, or a group's invitation link, opens the door when it's shut.
 */
class ReaderJoin extends Component
{
    use \App\Livewire\Concerns\Guarded;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    /** @var array<string, mixed> */
    public array $answers = [];

    /** Honeypot: people never see it, bots fill it in. */
    public string $website = '';

    public bool $done = false;

    /** From a signed invitation email: the address it was sent to, the groups it adds, who sent it. */
    #[Locked]
    public ?array $invite = null;

    /** From a group's invitation link (kept in the session when they followed it). */
    #[Locked]
    public ?string $groupInvite = null;

    /** @param  array{email: string, name?: string, groups?: list<string>, by?: int|null}|null  $invite  from a signed invitation link (checked by the page) */
    public function mount(?array $invite = null): void
    {
        if ($invite !== null && $invite['email'] !== '') {
            $this->invite = [
                'email' => mb_strtolower((string) $invite['email']),
                'groups' => array_values(array_filter((array) ($invite['groups'] ?? []))),
                'by' => $invite['by'] ?? null,
            ];
            $this->email = $this->invite['email'];
            $this->name = (string) ($invite['name'] ?? '');
        }
        $this->groupInvite = ReaderAccounts::groupInvite()?->handle;
        foreach (Readers::questions() as $q) {
            $this->answers[$q['key']] = $q['type'] === 'tick' ? false : '';
        }
    }

    /** May someone join from here? */
    public function open(): bool
    {
        return match (Readers::joining()) {
            'open', 'approval' => true,
            'invite' => $this->invite !== null || $this->groupInvite !== null,
            default => $this->invite !== null,
        };
    }

    public function join(): void
    {
        if (! $this->open()) {
            return;
        }
        if ($this->website !== '') {
            $this->done = true; // a bot: pretend, keep nothing

            return;
        }

        $this->name = trim(preg_replace('/\s+/', ' ', $this->name) ?? '');
        $this->email = $this->invite['email'] ?? mb_strtolower(trim($this->email));

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', new \App\Rules\StrongPassword([$this->name, $this->email])],
        ];
        $names = ['name' => __('your name'), 'email' => __('email address'), 'password' => __('password')];
        foreach (Readers::questions() as $q) {
            $key = 'answers.'.$q['key'];
            $names[$key] = mb_strtolower($q['label']);
            $rules[$key] = match ($q['type']) {
                'tick' => $q['required'] ? ['accepted'] : ['boolean'],
                'choice' => [$q['required'] ? 'required' : 'nullable', 'string', 'in:'.implode(',', array_map(fn ($o) => str_replace(',', '\\,', $o), $q['options']))],
                'long' => [$q['required'] ? 'required' : 'nullable', 'string', 'max:2000'],
                default => [$q['required'] ? 'required' : 'nullable', 'string', 'max:200'],
            };
        }
        $this->validate($rules, [], $names);

        if (! $this->guarded('join', ['name' => $this->name, 'email' => $this->email])) {
            return;
        }
        $key = 'reader-join:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', __('That’s a lot of tries. Please wait a few minutes.'));

            return;
        }
        RateLimiter::hit($key, 900);

        // Already has an account: say the same as for a new one, and tell the owner of the address instead.
        if ($existing = Reader::query()->where('email', $this->email)->first()) {
            rescue(fn () => $existing->notify(new \App\Notifications\ReaderAlreadyHere), null, false);
            $this->reset('password');
            $this->done = true;

            return;
        }

        $answers = [];
        foreach (Readers::questions() as $q) {
            $answers[$q['key']] = $q['type'] === 'tick' ? (bool) ($this->answers[$q['key']] ?? false) : trim((string) ($this->answers[$q['key']] ?? ''));
        }

        $invited = $this->invite !== null;
        $reader = Reader::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'answers' => $answers ?: null,
            // An invitation from the book was opened from the inbox: the address is proven, and they're let in.
            'state' => $invited ? 'active' : 'unconfirmed',
            'email_verified_at' => $invited ? now() : null,
            'approved_at' => $invited || $this->groupInvite ? now() : null,
            'invited_by' => $invited && $this->invite['by'] && \App\Models\User::whereKey($this->invite['by'])->exists() ? $this->invite['by'] : null,
        ]);

        // Groups: the ones the invitation names, and the one whose link they followed.
        foreach (array_filter([...($this->invite['groups'] ?? []), $this->groupInvite]) as $handle) {
            if ($group = Group::query()->where('handle', $handle)->first()) {
                ReaderAccounts::addTo($group, $reader);
            }
        }
        ReaderAccounts::forgetGroupInvite();

        ActivityLog::record('joined the site', 'people', 'reader', (string) $reader->id, $reader->name);

        if ($invited) {
            ReaderAccounts::signIn($reader);
            $this->redirect(Readers::landing());

            return;
        }

        ReaderAccounts::sendConfirm($reader);
        ReaderAccounts::signIn($reader); // they can see their page ("check your inbox") straight away
        $this->reset('password');
        $this->done = true;
    }

    public function render()
    {
        return view('livewire.site.readers.join', [
            'questions' => Readers::questions(),
            'joining' => Readers::joining(),
            'groupName' => $this->groupInvite ? Group::query()->where('handle', $this->groupInvite)->value('name') : null,
        ]);
    }
}
