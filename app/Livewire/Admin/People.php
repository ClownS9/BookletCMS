<?php

namespace App\Livewire\Admin;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use App\Livewire\Concerns\Stamps;
use Livewire\Component;

/**
 * People: everyone who can open the book, who's online, and what role they have.
 * Only admins can invite, change roles or remove people.
 *
 * Worked out when asked (#[Computed]):
 *
 * @property-read bool $canManage
 * @property-read User|null $chosen
 */
#[Layout('components.layouts.admin')]
#[Title('People')]
class People extends Component
{
    use Stamps;

    /** The book's own roles (custom ones come from Role::choices()). */
    public const ROLES = Role::BUILT_IN;

    #[Url(as: 'person', except: null)]
    public ?int $selected = null;

    public string $role = 'writer';

    public bool $inviting = false;

    public string $inviteName = '';

    public string $inviteEmail = '';

    public string $inviteRole = 'writer';

    // The Dashboard's table ----------------------------------------------------

    /** Search everyone by name or email. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Only one role: a role key, or '' for everyone. */
    #[Url(as: 'role', except: '')]
    public string $only = '';

    /** The drawer with one person (or one library card) open. */
    public bool $drawer = false;

    public function closeDrawer(): void
    {
        $this->drawer = false;
        $this->card = null;
        $this->temporaryPassword = null;
    }

        /** Shown once after an invite, so the admin can pass it on. */
    public ?string $temporaryPassword = null;

    public function mount(): void
    {
        // Opened at someone (?person=…): the Dashboard shows them straight away.
        $this->drawer = $this->selected !== null;
        $this->selected ??= request()->user()?->id;
        $this->role = (string) $this->chosen?->role;
        // "Invite someone" on the People sticky note opens the form straight away.
        $this->inviting = request()->boolean('invite') && $this->canManage;
    }

    #[Computed]
    public function canManage(): bool
    {
        return (bool) request()->user()?->isAdmin();
    }

    #[Computed]
    public function chosen(): ?User
    {
        return $this->selected ? User::find($this->selected) : null;
    }

    public function pick(int $id): void
    {
        $this->selected = $id;
        $this->drawer = true;
        $this->card = null;
        $this->temporaryPassword = null;
        unset($this->chosen);
        $this->role = (string) $this->chosen?->role;
    }

    public function saveRole(): void
    {
        abort_unless($this->canManage, 403);

        $this->validate(['role' => ['required', Rule::in(array_keys(Role::choices()))]]);
        $user = $this->chosen;

        if ($user->is(request()->user()) && $this->role !== 'admin' && User::where('role', 'admin')->count() === 1) {
            $this->addError('role', __('You’re the only admin. Make someone else an admin first.'));

            return;
        }

        $user->update(['role' => $this->role]);
        ActivityLog::record('changed the role of', 'people', 'user', (string) $user->id, $user->name, ['role' => $this->role]);
        unset($this->chosen);
        $this->stamp('Role saved.');
    }

    public function invite(): void
    {
        abort_unless($this->canManage, 403);

        $this->inviteEmail = mb_strtolower(trim($this->inviteEmail));
        $this->inviteName = trim($this->inviteName);

        $this->validate([
            'inviteName' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'inviteEmail' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            'inviteRole' => ['required', Rule::in(array_keys(Role::choices()))],
        ], [], ['inviteName' => 'name', 'inviteEmail' => 'email', 'inviteRole' => 'role']);

        $password = Str::password(14, symbols: false);

        $user = User::create([
            'name' => $this->inviteName,
            'email' => $this->inviteEmail,
            'role' => $this->inviteRole,
            'password' => $password,
        ]);

        ActivityLog::record('invited', 'people', 'user', (string) $user->id, $user->name);

        $this->reset('inviteName', 'inviteEmail', 'inviteRole', 'inviting');
        $this->pick($user->id);
        $this->temporaryPassword = $password;
    }

    /** For someone who lost their phone and their recovery codes: they set it up again. */
    public function resetTwoFactor(): void
    {
        abort_unless($this->canManage, 403);

        $user = $this->chosen;
        if (! $user || $user->is(request()->user()) || ! \App\Support\TwoFactor::on($user)) {
            return;
        }

        \App\Support\TwoFactor::disable($user);
        \App\Support\Devices::signOutOthers($user); // anyone using their old sign-in is out too
        ActivityLog::record('reset two-step sign-in for', 'people', 'user', (string) $user->id, $user->name);
        \App\Notifications\SecurityNotice::send($user, 'two_factor_off', $user->name);
        unset($this->chosen);
        $this->stamp('Two-step sign-in reset. They can sign in with their password and set it up again.');
    }

    /** Remove someone from their row's menu. */
    public function removeOne(int $id): void
    {
        $this->selected = $id;
        unset($this->chosen);
        $this->remove();
    }

    public function remove(): void
    {
        abort_unless($this->canManage, 403);

        $user = $this->chosen;

        if (! $user || $user->is(request()->user())) {
            return;
        }

        ActivityLog::record('removed', 'people', 'user', (string) $user->id, $user->name);
        $user->delete();

        $this->selected = request()->user()?->id;
        unset($this->chosen);
        $this->role = (string) $this->chosen?->role;
        $this->drawer = false;
        $this->stamp('Removed from the book.');
    }

    // Library cards: custom roles ----------------------------------------------------------

    /** The card open on the right page: a role id, 'new', or null (a person is shown). */
    public ?string $card = null;

    /** @var array{name: string, about: string, tone: string, base: string, chapters: array<string, string>, media: bool, stacy: bool} */
    public array $cardForm = ['name' => '', 'about' => '', 'tone' => 'sky', 'base' => 'writer', 'chapters' => [], 'media' => true, 'stacy' => true];

    public function newCard(): void
    {
        abort_unless($this->canManage, 403);
        $this->card = 'new';
        $this->drawer = true;
        $this->cardForm = ['name' => '', 'about' => '', 'tone' => 'sky', 'base' => 'writer', 'chapters' => $this->defaultChapters('writer'), 'media' => true, 'stacy' => true];
        $this->resetErrorBag();
    }

    public function editCard(int $id): void
    {
        $role = Role::findOrFail($id);
        $this->card = (string) $role->id;
        $this->drawer = true;
        $this->cardForm = [
            'name' => $role->name, 'about' => (string) $role->about, 'tone' => $role->tone, 'base' => $role->base,
            'chapters' => Booklet::collections()->all()->mapWithKeys(fn ($c) => [$c->handle() => $role->access($c->handle())])->all(),
            'media' => $role->media, 'stacy' => $role->stacy,
        ];
        $this->resetErrorBag();
    }

    public function closeCard(): void
    {
        $this->card = null;
        $this->drawer = false;
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'only');
    }

    /** Switching what it acts like resets chapters that would now be out of reach. */
    public function updatedCardFormBase(string $base): void
    {
        if ($base === 'writer') {
            $this->cardForm['chapters'] = array_map(fn ($a) => $a === 'publish' ? 'write' : $a, (array) $this->cardForm['chapters']);
        }
    }

    protected function defaultChapters(string $base): array
    {
        return Booklet::collections()->all()->mapWithKeys(fn ($c) => [$c->handle() => $base === 'editor' ? 'publish' : 'write'])->all();
    }

    public function saveCard(): void
    {
        abort_unless($this->canManage, 403);
        $existing = $this->card !== null && $this->card !== 'new' ? Role::find((int) $this->card) : null;

        $this->cardForm['name'] = trim((string) $this->cardForm['name']);
        $this->validate([
            'cardForm.name' => ['required', 'string', 'max:40', 'not_regex:/[<>]/', function ($attr, $value, $fail) use ($existing) {
                if (in_array(mb_strtolower($value), ['admin', 'editor', 'writer'], true)) {
                    $fail('That’s one of the book’s own roles. Pick another name.');
                }
                if (Role::query()->whereRaw('lower(name) = ?', [mb_strtolower($value)])->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists()) {
                    $fail('There’s already a card with that name.');
                }
            }],
            'cardForm.about' => ['nullable', 'string', 'max:140'],
            'cardForm.tone' => ['required', Rule::in(Role::TONES)],
            'cardForm.base' => ['required', Rule::in(['writer', 'editor'])],
            'cardForm.chapters' => ['array'],
            'cardForm.chapters.*' => [Rule::in(array_keys(Role::ACCESS))],
        ], [], ['cardForm.name' => 'name', 'cardForm.about' => 'description']);

        $chapters = [];
        foreach (Booklet::collections()->all() as $c) {
            $access = (string) ($this->cardForm['chapters'][$c->handle()] ?? 'write');
            // A card that acts like a writer can't publish anywhere: that's what an editor is for.
            $chapters[$c->handle()] = $this->cardForm['base'] === 'writer' && $access === 'publish' ? 'write' : $access;
        }

        $role = $existing ?? new Role(['key' => Role::keyFor($this->cardForm['name'])]);
        $role->fill([
            'name' => $this->cardForm['name'],
            'about' => trim((string) $this->cardForm['about']) ?: null,
            'tone' => $this->cardForm['tone'],
            'base' => $this->cardForm['base'],
            'chapters' => $chapters,
            'media' => (bool) $this->cardForm['media'],
            'stacy' => (bool) $this->cardForm['stacy'],
        ])->save();

        ActivityLog::record($existing ? 'changed the library card' : 'made the library card', 'people', 'role', $role->key, $role->name);
        $this->card = (string) $role->id;
        $this->stamp($existing ? 'Card saved. Everyone holding it has the new rules now.' : 'Card made. Give it to someone from their page.');
    }

    /** Tear up a card: anyone holding it goes back to the role it acted like. */
    public function deleteCard(): void
    {
        abort_unless($this->canManage, 403);
        $role = Role::findOrFail((int) $this->card);
        $moved = User::where('role', $role->key)->update(['role' => $role->base]);
        ActivityLog::record('tore up the library card', 'people', 'role', $role->key, $role->name, ['moved' => $moved]);
        $role->delete();
        $this->card = null;
        $this->drawer = false;
        $this->stamp($moved ? __('Card torn up.').' '.trans_choice($role->base === 'editor' ? ':count person went back to being an editor.|:count people went back to being editors.' : ':count person went back to being a writer.|:count people went back to being writers.', $moved) : __('Card torn up.'));
    }

    /** See the whole book as someone with this role would (only for you, until you stop). */
    public function viewAs(string $key): void
    {
        abort_unless($this->canManage, 403);
        abort_unless($key !== 'admin' && array_key_exists($key, Role::choices()), 404);
        session()->put(User::VIEW_AS, $key);
        ActivityLog::record('tried the book as', 'people', 'role', $key, Role::choices()[$key]['name']);
        $this->redirectRoute('admin.contents', navigate: false);
    }

    public function render()
    {
        $people = User::query()->orderByRaw("CASE role WHEN 'admin' THEN 0 WHEN 'editor' THEN 1 ELSE 2 END")->orderBy('name')->get();
        $entries = Booklet::entries()->all();
        $chosen = $this->chosen;

        $mine = $chosen
            ? $entries->filter(fn (Entry $e) => (string) $e->get('author') === (string) $chosen->id)
            : collect();

        $desk = \App\Support\YourBook::desk();
        $roles = Role::choices();
        if (! array_key_exists($this->only, $roles)) {
            $this->only = '';
        }
        $written = $desk ? $entries->countBy(fn (Entry $e) => (string) $e->get('author')) : collect();
        $shown = $desk ? $people
            ->when($this->only !== '', fn ($c) => $c->where('role', $this->only))
            ->when(trim($this->search) !== '', fn ($c) => $c->filter(fn (User $u) => \Illuminate\Support\Str::contains(
                \Illuminate\Support\Str::lower($u->name.' '.$u->email.' '.$u->byline), \Illuminate\Support\Str::lower(trim($this->search)))))
            ->values() : $people;

        return view($desk ? 'livewire.admin.people-table' : 'livewire.admin.people', [
            'shown' => $shown,
            'written' => $written,
            'people' => $people,
            'online' => $people->filter->isOnline()->count(),
            'roles' => Role::choices(),
            'cards' => Role::cards()->values(),
            'holders' => User::query()->selectRaw('role, count(*) as n')->groupBy('role')->pluck('n', 'role'),
            'collections' => Booklet::collections()->all()->values(),
            'stats' => [
                'entries' => $mine->count(),
                'drafts' => $mine->filter(fn (Entry $e) => ! $e->published())->count(),
                'joined' => $chosen?->created_at?->format('M Y'),
            ],
            'lately' => $chosen
                ? ActivityLog::where('user_id', $chosen->id)->latest('created_at')->limit(5)->get()
                : collect(),
        ]);
    }
}
