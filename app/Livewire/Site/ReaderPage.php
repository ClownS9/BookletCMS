<?php

namespace App\Livewire\Site;

use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Reader;
use App\Models\Subscriber;
use App\Support\Newsletter;
use App\Support\ReaderAccounts;
use App\Support\Readers;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

/**
 * A reader's own page on the site: where they stand (confirm your address,
 * waiting to be let in), their groups, their details, their password, new
 * posts by email, and leaving.
 */
class ReaderPage extends Component
{
    public string $name = '';

    /** @var array<string, mixed> */
    public array $answers = [];

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $leavePassword = '';

    public ?string $notice = null;

    public function mount(): void
    {
        $reader = $this->reader();
        $this->name = $reader->name;
        foreach (Readers::questions() as $q) {
            $this->answers[$q['key']] = $reader->answers[$q['key']] ?? ($q['type'] === 'tick' ? false : '');
        }
    }

    protected function reader(): Reader
    {
        $reader = Readers::signedIn();
        if (! $reader) {
            $this->redirect(route('readers.sign-in'));
            abort(403);
        }

        return $reader;
    }

    public function saveDetails(): void
    {
        $reader = $this->reader();
        $this->name = trim(preg_replace('/\s+/', ' ', $this->name) ?? '');
        $rules = ['name' => ['required', 'string', 'max:120']];
        $names = ['name' => __('your name')];
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
        $answers = (array) ($reader->answers ?? []);
        foreach (Readers::questions() as $q) {
            $answers[$q['key']] = $q['type'] === 'tick' ? (bool) ($this->answers[$q['key']] ?? false) : trim((string) ($this->answers[$q['key']] ?? ''));
        }
        $reader->forceFill(['name' => $this->name, 'answers' => $answers ?: null])->save();
        $this->notice = __('Saved.');
    }

    public function changePassword(): void
    {
        $reader = $this->reader();
        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', new \App\Rules\StrongPassword([$reader->name, $reader->email])],
        ], [], ['currentPassword' => __('your password now'), 'newPassword' => __('new password')]);
        if (! Hash::check($this->currentPassword, $reader->password)) {
            $this->addError('currentPassword', __('That isn’t your password now.'));

            return;
        }
        // A new remember-me token too, so "keep me signed in" on other devices stops working.
        $reader->forceFill(['password' => $this->newPassword, 'remember_token' => \Illuminate\Support\Str::random(60)])->save();
        $this->reset('currentPassword', 'newPassword');
        $this->notice = __('Password changed.');
    }

    public function sendConfirmAgain(): void
    {
        $reader = $this->reader();
        $key = 'reader-confirm-again:'.$reader->id;
        if (! $reader->confirmed() && \Illuminate\Support\Facades\RateLimiter::attempt($key, 3, fn () => true, 3600)) {
            ReaderAccounts::sendConfirm($reader);
        }
        $this->notice = __('Sent. Look in your inbox (and the spam folder) for an email from us.');
    }

    public function joinGroup(int $id): void
    {
        $reader = $this->reader();
        $group = Group::find($id);
        if (! $group || ! $reader->isActive()) {
            return;
        }
        $state = ReaderAccounts::joinFromSite($group, $reader);
        $this->notice = match ($state) {
            'in' => __('You’re in :group.', ['group' => $group->name]),
            'asked' => __('Asked. You’ll be in :group once they say yes.', ['group' => $group->name]),
            default => null,
        };
    }

    public function leaveGroup(int $id): void
    {
        $reader = $this->reader();
        if ($group = Group::find($id)) {
            ReaderAccounts::removeFrom($group, $reader);
            $this->notice = __('You’ve left :group.', ['group' => $group->name]);
        }
    }

    public function toggleNewsletter(): void
    {
        $reader = $this->reader();
        if (! Newsletter::enabled() || ! $reader->confirmed()) {
            return;
        }
        $sub = Subscriber::query()->where('email', $reader->email)->first();
        if ($sub && $sub->status() === 'subscribed') {
            $sub->forceFill(['unsubscribed_at' => now()])->save();
            $this->notice = __('No more new posts by email.');

            return;
        }
        // Their address is confirmed already, so no second email to confirm it.
        $sub ??= new Subscriber(['email' => $reader->email, 'source' => 'reader']);
        $sub->forceFill(['name' => $sub->name ?: $reader->name, 'confirmed_at' => now(), 'unsubscribed_at' => null])->save();
        $this->notice = __('New posts will come to :email.', ['email' => $reader->email]);
    }

    public function leave(): void
    {
        $reader = $this->reader();
        $this->validate(['leavePassword' => ['required', 'string']], [], ['leavePassword' => __('password')]);
        if (! Hash::check($this->leavePassword, $reader->password)) {
            $this->addError('leavePassword', __('That isn’t your password.'));

            return;
        }
        ActivityLog::record('deleted their reader account', 'people', 'reader', (string) $reader->id, $reader->name);
        GroupMember::query()->where('member_type', 'reader')->where('member_id', $reader->id)->delete();
        ReaderAccounts::signOut();
        $reader->delete();
        session()->flash('readers.left', true);
        $this->redirect(url('/'));
    }

    public function render()
    {
        $reader = $this->reader();
        $mine = GroupMember::query()->where('member_type', 'reader')->where('member_id', $reader->id)->pluck('state', 'group_id');
        $groups = Group::query()->orderBy('name')->get()
            ->filter(fn (Group $g) => $mine->has($g->id) || in_array($g->joining, ['open', 'approval'], true))
            ->map(fn (Group $g) => ['group' => $g, 'state' => $mine->get($g->id)]);
        $sub = Subscriber::query()->where('email', $reader->email)->first();

        return view('livewire.site.readers.page', [
            'reader' => $reader,
            'groups' => $groups,
            'questions' => Readers::questions(),
            'newsletter' => Newsletter::enabled(),
            'subscribed' => $sub?->status() === 'subscribed',
        ]);
    }
}
