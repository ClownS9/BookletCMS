<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Reader;
use App\Models\User;
use App\Support\ReaderAccounts;
use App\Support\Readers as ReaderSettings;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Readers & groups: the people with an account on the public site, the
 * groups that gather them (and people from the book), and how people join.
 * Editors and admins; how people join is the admin's.
 */
#[Layout('components.layouts.admin')]
#[Title('Readers & groups')]
class Readers extends Component
{
    use Stamps, WithPagination;

    #[Url(except: 'readers')]
    public string $tab = 'readers';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $show = 'all';

    #[Url(except: '')]
    public string $inGroup = '';

    /** The reader opened in the list. */
    public ?int $open = null;

    /** The group being looked after. */
    #[Url(as: 'group', except: null)]
    public ?int $groupId = null;

    // Inviting
    public string $inviteEmail = '';

    public string $inviteName = '';

    /** @var list<string> */
    public array $inviteGroups = [];

    // A group
    public string $groupName = '';

    public string $groupAbout = '';

    public string $groupJoining = 'closed';

    public string $memberSearch = '';

    // How people join (admins)
    /** @var array<string, mixed> */
    public array $settings = [];

    /** @var list<array{key: string, label: string, type: string, options: string, required: bool}> */
    public array $questions = [];

    public function mount(): void
    {
        $this->authorise();
        if (! in_array($this->tab, ['readers', 'groups', 'joining'], true) || ($this->tab === 'joining' && ! request()->user()->isAdmin())) {
            $this->tab = 'readers';
        }
        $this->loadSettings();
        if ($this->groupId) {
            $this->openGroup($this->groupId);
        }
    }

    protected function authorise(): void
    {
        abort_unless(request()->user()?->arranges(), 403);
    }

    protected function loadSettings(): void
    {
        ReaderSettings::forget();
        $s = ReaderSettings::settings();
        $this->settings = array_diff_key($s, ['questions' => true]);
        $this->questions = array_map(fn ($q) => [
            'key' => $q['key'], 'label' => $q['label'], 'type' => $q['type'],
            'options' => implode(', ', $q['options']), 'required' => $q['required'],
        ], ReaderSettings::questions());
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    public function updatedInGroup(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['readers', 'groups', 'joining'], true) && ($tab !== 'joining' || request()->user()->isAdmin()) ? $tab : 'readers';
        $this->resetErrorBag();
    }

    // Readers ---------------------------------------------------------------------------------

    public function toggle(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
    }

    protected function reader(int $id): ?Reader
    {
        $this->authorise();

        return Reader::find($id);
    }

    public function letIn(int $id): void
    {
        if ($reader = $this->reader($id)) {
            ReaderAccounts::approve($reader);
            ActivityLog::record('let in the reader', 'people', 'reader', (string) $reader->id, $reader->name);
            $this->stamp(__(':name is in. They’ve had an email to say so.', ['name' => $reader->name]));
        }
    }

    public function block(int $id): void
    {
        if ($reader = $this->reader($id)) {
            $reader->forceFill(['state' => 'blocked', 'remember_token' => Str::random(60)])->save();
            ActivityLog::record('blocked the reader', 'people', 'reader', (string) $reader->id, $reader->name);
            $this->stamp(__(':name can’t sign in any more.', ['name' => $reader->name]));
        }
    }

    public function unblock(int $id): void
    {
        if ($reader = $this->reader($id)) {
            $reader->forceFill(['state' => $reader->confirmed() ? 'active' : 'unconfirmed'])->save();
            ActivityLog::record('unblocked the reader', 'people', 'reader', (string) $reader->id, $reader->name);
            $this->stamp(__(':name can sign in again.', ['name' => $reader->name]));
        }
    }

    public function confirmAgain(int $id): void
    {
        if (($reader = $this->reader($id)) && ! $reader->confirmed()) {
            ReaderAccounts::sendConfirm($reader);
            $this->stamp(__('The confirm email went to :email again.', ['email' => $reader->email]));
        }
    }

    public function remove(int $id): void
    {
        if ($reader = $this->reader($id)) {
            ActivityLog::record('removed the reader', 'people', 'reader', (string) $reader->id, $reader->name);
            GroupMember::query()->where('member_type', 'reader')->where('member_id', $reader->id)->delete();
            $reader->delete();
            $this->open = null;
            $this->stamp(__('Removed. Their account is gone; their comments stay.'));
        }
    }

    public function turnAway(int $id): void
    {
        if (($reader = $this->reader($id)) && $reader->state === 'waiting') {
            ActivityLog::record('turned away the reader', 'people', 'reader', (string) $reader->id, $reader->name);
            $reader->delete();
            $this->stamp(__('Turned away. Their account is gone.'));
        }
    }

    /** Put a reader in a group, or take them out (from their card). */
    public function toggleMembership(int $readerId, int $groupId): void
    {
        $reader = $this->reader($readerId);
        $group = Group::find($groupId);
        if (! $reader || ! $group) {
            return;
        }
        $in = GroupMember::query()->where(['group_id' => $group->id, 'member_type' => 'reader', 'member_id' => $reader->id, 'state' => 'in'])->exists();
        $in ? ReaderAccounts::removeFrom($group, $reader) : ReaderAccounts::addTo($group, $reader);
        ActivityLog::record($in ? 'took a reader out of' : 'put a reader in', 'people', 'group', (string) $group->id, $group->name);
    }

    public function invite(): void
    {
        $this->authorise();
        $this->inviteEmail = mb_strtolower(trim($this->inviteEmail));
        $this->inviteName = trim($this->inviteName);
        $this->validate([
            'inviteEmail' => ['required', 'email:rfc', 'max:190'],
            'inviteName' => ['nullable', 'string', 'max:120'],
        ], [], ['inviteEmail' => __('email address'), 'inviteName' => __('name')]);

        $groups = array_values(array_intersect($this->inviteGroups, array_keys(Group::choices())));
        if ($reader = Reader::query()->where('email', $this->inviteEmail)->first()) {
            // Already has an account: just put them in the groups.
            foreach ($groups as $handle) {
                ReaderAccounts::addTo(Group::query()->where('handle', $handle)->firstOrFail(), $reader);
            }
            $this->reset('inviteEmail', 'inviteName', 'inviteGroups');
            $this->stamp($groups !== [] ? __('They have an account already, so they’re in those groups now.') : __('They have an account already.'));

            return;
        }
        ReaderAccounts::invite($this->inviteEmail, $this->inviteName, $groups, request()->user());
        ActivityLog::record('invited a reader', 'people', 'reader', $this->inviteEmail, $this->inviteEmail);
        $this->reset('inviteEmail', 'inviteName', 'inviteGroups');
        $this->stamp(__('Invitation sent. The link works for :days days.', ['days' => ReaderAccounts::INVITE_DAYS]));
    }

    // Groups ----------------------------------------------------------------------------------

    public function newGroup(): void
    {
        $this->authorise();
        $this->groupId = 0;
        $this->groupName = '';
        $this->groupAbout = '';
        $this->groupJoining = 'closed';
        $this->tab = 'groups';
        $this->resetErrorBag();
    }

    public function openGroup(int $id): void
    {
        $this->authorise();
        $group = Group::find($id);
        if (! $group) {
            $this->groupId = null;

            return;
        }
        $this->groupId = $group->id;
        $this->groupName = $group->name;
        $this->groupAbout = (string) $group->about;
        $this->groupJoining = $group->joining;
        $this->memberSearch = '';
        $this->tab = 'groups';
        $this->resetErrorBag();
    }

    public function closeGroup(): void
    {
        $this->groupId = null;
    }

    public function saveGroup(): void
    {
        $this->authorise();
        $this->groupName = trim(preg_replace('/\s+/', ' ', $this->groupName) ?? '');
        $this->validate([
            'groupName' => ['required', 'string', 'max:80', \Illuminate\Validation\Rule::unique('groups', 'name')->ignore($this->groupId)],
            'groupAbout' => ['nullable', 'string', 'max:300'],
            'groupJoining' => ['required', 'in:'.implode(',', array_keys(Group::JOINING))],
        ], ['groupName.unique' => __('There’s a group called that already.')], ['groupName' => __('the group’s name'), 'groupAbout' => __('what it’s for')]);

        $group = $this->groupId ? Group::find($this->groupId) : null;
        $isNew = ! $group;
        $group ??= new Group(['handle' => Group::handleFor($this->groupName)]);
        $group->fill(['name' => $this->groupName, 'about' => trim($this->groupAbout) ?: null, 'joining' => $this->groupJoining])->save();
        if ($group->joining === 'invite') {
            $group->inviteToken();
        }
        $this->groupId = $group->id;
        ActivityLog::record($isNew ? 'made the group' : 'changed the group', 'people', 'group', (string) $group->id, $group->name);
        $this->stamp($isNew ? __('Group made. Open posts and pages to it from “Who can read it” in the editor.') : __('Saved.'));
    }

    public function deleteGroup(): void
    {
        $this->authorise();
        if ($group = Group::find($this->groupId)) {
            ActivityLog::record('deleted the group', 'people', 'group', (string) $group->id, $group->name);
            $group->delete();
            $this->groupId = null;
            $this->stamp(__('Group deleted. Posts and pages that were open to it are now for any signed-in reader.'));
        }
    }

    public function newInviteLink(): void
    {
        $this->authorise();
        if ($group = Group::find($this->groupId)) {
            $group->forceFill(['invite_token' => Str::random(40)])->save();
            $this->stamp(__('A new link. The old one doesn’t work any more.'));
        }
    }

    public function addMember(string $type, int $id): void
    {
        $this->authorise();
        $group = Group::find($this->groupId);
        $who = $type === 'reader' ? Reader::find($id) : ($type === 'user' ? User::find($id) : null);
        if ($group && $who) {
            ReaderAccounts::addTo($group, $who);
            $this->memberSearch = '';
            ActivityLog::record('added someone to', 'people', 'group', (string) $group->id, $group->name);
        }
    }

    public function removeMember(int $memberId): void
    {
        $this->authorise();
        $member = GroupMember::query()->where('group_id', $this->groupId)->whereKey($memberId)->first();
        if ($member) {
            $member->delete();
            \App\Support\Remember::forget();
            ActivityLog::record('took someone out of', 'people', 'group', (string) $member->group_id, (string) $member->group?->name);
        }
    }

    /** Someone asked to join a group: yes, or no. */
    public function answerAsk(int $memberId, bool $yes): void
    {
        $this->authorise();
        $member = GroupMember::query()->whereKey($memberId)->where('state', 'asked')->first();
        if (! $member) {
            return;
        }
        $name = (string) $member->group?->name;
        $yes ? $member->forceFill(['state' => 'in'])->save() : $member->delete();
        \App\Support\Remember::forget();
        ActivityLog::record($yes ? 'let someone into' : 'turned someone away from', 'people', 'group', (string) $member->group_id, $name);
    }

    // How people join (admins) -----------------------------------------------------------------

    public function addQuestion(): void
    {
        if (count($this->questions) < ReaderSettings::MAX_QUESTIONS) {
            $this->questions[] = ['key' => '', 'label' => '', 'type' => 'text', 'options' => '', 'required' => false];
        }
    }

    public function removeQuestion(int $i): void
    {
        unset($this->questions[$i]);
        $this->questions = array_values($this->questions);
    }

    public function moveQuestion(int $i, int $by): void
    {
        $j = $i + $by;
        if (isset($this->questions[$i], $this->questions[$j])) {
            [$this->questions[$i], $this->questions[$j]] = [$this->questions[$j], $this->questions[$i]];
        }
    }

    public function saveJoining(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->validate([
            'settings.joining' => ['required', 'in:'.implode(',', array_keys(ReaderSettings::JOINING))],
            'settings.after' => ['nullable', 'string', 'max:200', 'regex:#^/(?!/)#'],
            'settings.join_title' => ['nullable', 'string', 'max:120'],
            'settings.join_lede' => ['nullable', 'string', 'max:400'],
            'settings.signin_title' => ['nullable', 'string', 'max:120'],
            'settings.locked_lede' => ['nullable', 'string', 'max:400'],
            'settings.welcome' => ['nullable', 'string', 'max:600'],
            'questions.*.label' => ['required', 'string', 'max:120'],
            'questions.*.type' => ['required', 'in:'.implode(',', array_keys(ReaderSettings::QUESTION_TYPES))],
            'questions.*.options' => ['nullable', 'string', 'max:600'],
        ], ['settings.after.regex' => __('Start it with /, like /welcome.')], [
            'settings.after' => __('where they land'), 'questions.*.label' => __('the question'), 'questions.*.options' => __('the choices'),
        ]);

        $questions = [];
        $used = [];
        foreach ($this->questions as $q) {
            $options = $q['type'] === 'choice' ? array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $q['options'])), fn ($o) => $o !== ''))) : [];
            if ($q['type'] === 'choice' && $options === []) {
                $this->addError('questions', __('“:question” needs its choices, separated by commas.', ['question' => $q['label']]));

                return;
            }
            // Keep an existing question's key, so the answers people gave stay with it.
            $key = $q['key'] !== '' ? $q['key'] : (Str::slug($q['label'], '_') ?: 'question');
            $key = Str::limit($key, 40, '');
            for ($n = 2, $base = $key; in_array($key, $used, true); $n++) {
                $key = $base.'_'.$n;
            }
            $used[] = $key;
            $questions[] = ['key' => $key, 'label' => trim($q['label']), 'type' => $q['type'], 'options' => $options, 'required' => (bool) $q['required']];
        }

        $was = ReaderSettings::joining();
        ReaderSettings::save([
            'joining' => $this->settings['joining'],
            'link' => (bool) ($this->settings['link'] ?? true),
            'after' => trim((string) ($this->settings['after'] ?? '')),
            'questions' => $questions,
            'join_title' => trim((string) ($this->settings['join_title'] ?? '')),
            'join_lede' => trim((string) ($this->settings['join_lede'] ?? '')),
            'signin_title' => trim((string) ($this->settings['signin_title'] ?? '')),
            'locked_lede' => trim((string) ($this->settings['locked_lede'] ?? '')),
            'welcome' => trim((string) ($this->settings['welcome'] ?? '')),
        ]);
        \App\Support\PageCache::flush();
        ActivityLog::record('changed how readers join', 'settings', 'setting', 'readers', __('Readers & groups'));
        $this->loadSettings();
        $this->stamp($was === 'off' && ReaderSettings::joining() !== 'off'
            ? __('Saved. “Sign in” is on the site now, and people can join.')
            : __('Saved.'));
    }

    public function render()
    {
        $groups = Group::query()->withCount([
            'members as in_count' => fn ($q) => $q->where('state', 'in'),
            'members as asked_count' => fn ($q) => $q->where('state', 'asked'),
        ])->orderBy('name')->get();

        $readers = Reader::query()
            ->when(in_array($this->show, Reader::STATES, true), fn ($q) => $q->where('state', $this->show))
            ->when($this->inGroup !== '', fn ($q) => $q->whereIn('id', GroupMember::query()->select('member_id')->where('member_type', 'reader')->where('state', 'in')
                ->whereIn('group_id', Group::query()->select('id')->where('handle', $this->inGroup))))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->where('email', 'like', '%'.mb_strtolower($this->search).'%')->orWhere('name', 'like', '%'.$this->search.'%')))
            ->latest()
            ->paginate(25);

        $memberGroups = GroupMember::query()->where('member_type', 'reader')->whereIn('member_id', $readers->pluck('id'))->get()->groupBy('member_id');

        $group = $this->groupId ? Group::find($this->groupId) : null;
        $members = $group ? $group->members()->orderByRaw("state = 'asked' desc")->latest()->get()->map(fn (GroupMember $m) => ['member' => $m, 'who' => $m->who()])->filter(fn ($r) => $r['who'] !== null)->values() : collect();
        $found = collect();
        if ($group && mb_strlen(trim($this->memberSearch)) >= 2) {
            $q = '%'.trim($this->memberSearch).'%';
            $inIds = $group->members()->get()->groupBy('member_type')->map(fn ($c) => $c->pluck('member_id')->all());
            $found = Reader::query()->where(fn ($w) => $w->where('name', 'like', $q)->orWhere('email', 'like', $q))->whereNotIn('id', $inIds['reader'] ?? [])->limit(6)->get()->map(fn ($r) => ['type' => 'reader', 'who' => $r])
                ->concat(User::query()->where(fn ($w) => $w->where('name', 'like', $q)->orWhere('email', 'like', $q))->whereNotIn('id', $inIds['user'] ?? [])->limit(4)->get()->map(fn ($u) => ['type' => 'user', 'who' => $u]));
        }

        $asks = GroupMember::query()->where('state', 'asked')->with('group')->latest()->limit(20)->get()
            ->map(fn (GroupMember $m) => ['member' => $m, 'who' => $m->who()])->filter(fn ($r) => $r['who'] !== null)->values();

        return view('livewire.admin.readers', [
            'readers' => $readers,
            'memberGroups' => $memberGroups,
            'groups' => $groups,
            'group' => $group,
            'members' => $members,
            'found' => $found,
            'waiting' => Reader::query()->where('state', 'waiting')->oldest()->limit(20)->get(),
            'asks' => $asks,
            'counts' => [
                'active' => Reader::query()->where('state', 'active')->count(),
                'waiting' => Reader::query()->where('state', 'waiting')->count(),
                'unconfirmed' => Reader::query()->where('state', 'unconfirmed')->count(),
                'blocked' => Reader::query()->where('state', 'blocked')->count(),
            ],
            'questionList' => ReaderSettings::questions(),
            'joiningNow' => ReaderSettings::joining(),
            'isAdmin' => request()->user()->isAdmin(),
        ]);
    }
}
