<?php

namespace App\Livewire\Admin;

use App\Models\ActivityLog;
use App\Models\AiProvider;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Livewire\Concerns\Stamps;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Your account ("Ex libris"): profile, password and the little choices
 * about how the book behaves for you.
 */
#[Layout('components.layouts.admin')]
#[Title('Your account')]
class Account extends Component
{
    use WithFileUploads, Stamps;

    public const PREFERENCES = [
        'notify_comments' => ['New comments on my entries', 'An email when someone leaves a note', true],
        'notify_messages' => ['Messages and form answers', 'An email when someone writes from the Contact page or fills in one of your forms (admins and editors)', true],
        'notify_reviews' => ['Reviews', 'An email when a draft is ready for review, sent back, or published', true],
        'stacy_suggestions' => ['Stacy’s suggestions', 'Little nudges on Contents, like drafts going quiet', true],
        'stacy_greet' => ['Stacy says hello', 'A greeting when you sign in or come back after a while', true],
        'weekly_summary' => ['Weekly summary', 'A letter from Stacy every Monday morning: visits, what got written, what’s next', false],
        'night' => ['Night reading', 'A darker book for late evenings', false],
        'page_sound' => ['Page-turn sound', 'A soft paper rustle when you turn a page', true],
        'checklist' => ['First steps', 'A short list on Contents of things to set up, ticked off as you do them', true],
        'stickers' => ['Milestone stickers', 'A sticker for your first post, ten thousand words, the book’s birthday…', true],
    ];

    public string $name = '';

    public string $byline = '';

    public string $email = '';

    public string $bio = '';

    public $avatarUpload = null;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    /** Changing the sign-in email needs the password too (so an open laptop isn't enough to take the account). */
    public string $emailPassword = '';

    public array $preferences = [];

    /** The book's language for this person (App\Support\BookLanguage), or '' to follow the browser. */
    public string $language = '';

    /** Stacy's service and model for this person ("" = the book's default). */
    public string $stacyService = '';

    public string $stacyModel = '';

    public bool $stacySaved = false;

    /** How Stacy sounds and listens for this person. */
    public bool $stacySpeak = true;

    public string $stacyVoice = '';

    public float $stacyRate = 1.0;

    public bool $stacyWake = false;

    public string $stacyLang = '';

    /** "natural" (an AI voice through OpenRouter or OpenAI) or "browser". */
    public string $stacyEngine = 'natural';

    public string $stacyNaturalVoice = 'coral';

    public string $stacyStyle = '';

    public bool $voiceSaved = false;

    public bool $profileSaved = false;

    public bool $passwordSaved = false;

    public function mount(): void
    {
        $user = request()->user();

        $this->name = $user->name;
        $this->byline = (string) $user->byline;
        $this->email = $user->email;
        $this->bio = (string) $user->bio;

        foreach (self::PREFERENCES as $key => [, , $default]) {
            $this->preferences[$key] = (bool) ($user->preferences[$key] ?? $default);
        }

        $this->language = (string) ($user->preferences['locale'] ?? '');
        $this->stacyService = (string) ($user->preferences['stacy_service'] ?? '');
        $this->stacyModel = (string) ($user->preferences['stacy_model'] ?? '');

        $p = (array) $user->preferences;
        $this->stacySpeak = (bool) ($p['stacy_speak'] ?? true);
        $this->stacyVoice = (string) ($p['stacy_voice'] ?? '');
        $this->stacyRate = (float) ($p['stacy_rate'] ?? 1.0);
        $this->stacyWake = (bool) ($p['stacy_wake'] ?? false);
        $this->stacyLang = (string) ($p['stacy_lang'] ?? '');
        $this->stacyEngine = (string) ($p['stacy_engine'] ?? 'natural');
        $this->stacyNaturalVoice = (string) ($p['stacy_natural_voice'] ?? 'coral');
        $this->stacyStyle = (string) ($p['stacy_style'] ?? '');
    }

    /** The book in another language: saved, remembered in this browser (for the sign-in page), and the page drawn again in it. */
    public function updatedLanguage(string $code): void
    {
        if ($code !== '' && ! isset(\App\Support\BookLanguage::LANGUAGES[$code])) {
            return;
        }
        $user = request()->user();
        $preferences = array_merge((array) $user->preferences, ['locale' => $code]);
        if ($code === '') {
            unset($preferences['locale']); // follow the browser
            \Illuminate\Support\Facades\Cookie::queue(\Illuminate\Support\Facades\Cookie::forget(\App\Support\BookLanguage::COOKIE));
        } else {
            \Illuminate\Support\Facades\Cookie::queue(\App\Support\BookLanguage::COOKIE, $code, 60 * 24 * 365);
        }
        $user->update(['preferences' => $preferences]);
        $code = $code !== '' ? $code : \App\Support\BookLanguage::fromBrowser(request());
        \App\Support\BookLanguage::use($code);
        $this->stamp(__('The book speaks :language now.', ['language' => \App\Support\BookLanguage::LANGUAGES[$code][1]]), afterRedirect: true);
        $this->redirectRoute('admin.account', navigate: false);
    }

    public function saveVoice(): void
    {
        $this->validate([
            'stacyVoice' => ['nullable', 'string', 'max:255'],
            'stacyRate' => ['numeric', 'between:0.6,2'],
            'stacyLang' => ['nullable', 'string', 'max:20', 'regex:/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/'],
            'stacyEngine' => ['required', 'in:natural,browser'],
            'stacyNaturalVoice' => ['required', 'in:'.implode(',', array_keys(\App\Stacy\Catalog::VOICES))],
            'stacyStyle' => ['nullable', 'string', 'max:400'],
        ], [], ['stacyRate' => 'speed', 'stacyLang' => 'language', 'stacyStyle' => 'speaking style']);

        $user = request()->user();
        $user->update(['preferences' => array_filter(array_merge((array) $user->preferences, [
            'stacy_speak' => $this->stacySpeak,
            'stacy_voice' => $this->stacyVoice ?: null,
            'stacy_rate' => round($this->stacyRate, 2),
            'stacy_wake' => $this->stacyWake,
            'stacy_lang' => $this->stacyLang ?: null,
            'stacy_engine' => $this->stacyEngine,
            'stacy_natural_voice' => $this->stacyNaturalVoice,
            'stacy_style' => trim($this->stacyStyle) ?: null,
        ]), fn ($v) => $v !== null)]);

        // A cleared style goes back to Stacy's own.
        if (trim($this->stacyStyle) === '') {
            $preferences = (array) $user->fresh()->preferences;
            unset($preferences['stacy_style']);
            $user->update(['preferences' => $preferences]);
        }

        $this->voiceSaved = true;
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'stacy') && ! in_array($property, ['stacyService', 'stacyModel'], true)) {
            $this->voiceSaved = false;
        }
    }

    public function updatedAvatarUpload(): void
    {
        $this->validate(['avatarUpload' => ['image', 'mimes:jpg,jpeg,png,gif,webp,avif', 'max:4096', 'dimensions:max_width=8000,max_height=8000']], [], ['avatarUpload' => 'picture']);

        $user = request()->user();
        $path = $this->avatarUpload->storeAs(
            'avatars',
            $user->id.'-'.Str::lower(Str::random(6)).'.'.\App\Support\SafeUpload::extension($this->avatarUpload, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif']),
            'public',
        );

        if ($user->avatar) {
            \App\Support\Disk::of('public')->delete($user->avatar);
        }

        $user->update(['avatar' => $path]);
        $this->avatarUpload = null;
    }

    public function removeAvatar(): void
    {
        $user = request()->user();

        if ($user->avatar) {
            \App\Support\Disk::of('public')->delete($user->avatar);
            $user->update(['avatar' => null]);
        }
    }

    public function saveProfile(): void
    {
        $user = request()->user();

        $this->name = trim($this->name);
        $this->email = mb_strtolower(trim($this->email));
        $oldEmail = (string) $user->email;
        $emailChanged = $this->email !== mb_strtolower($oldEmail);

        $this->validate([
            'name' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'byline' => ['nullable', 'string', 'max:120', 'not_regex:/[<>]/'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'emailPassword' => [$emailChanged ? 'required' : 'nullable', ...($emailChanged ? ['current_password'] : [])],
            'bio' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.not_regex' => 'Names can’t contain < or >.',
            'byline.not_regex' => 'The byline can’t contain < or >.',
            'emailPassword.required' => 'Type your password to change the email you sign in with.',
            'emailPassword.current_password' => 'That isn’t your password.',
        ], ['emailPassword' => 'password']);

        $user->update([
            'name' => $this->name,
            'byline' => $this->byline ?: null,
            'email' => $this->email,
            'bio' => $this->bio ?: null,
            'preferences' => array_merge((array) $user->preferences, $this->preferences),
        ]);

        ActivityLog::record('updated their profile', 'people', 'user', (string) $user->id, $user->name);
        $this->emailPassword = '';

        if ($emailChanged) {
            // A note to the old address, in case it wasn't them.
            \App\Notifications\SecurityNotice::send(\Illuminate\Support\Facades\Notification::route('mail', $oldEmail), 'email', $user->name, $this->email);
        }

        $this->profileSaved = true;
        $this->stamp('Saved.', afterRedirect: true);

        // Night reading changes the whole book, so reload the page.
        $this->redirectRoute('admin.account', navigate: true);
    }

    public function togglePreference(string $key): void
    {
        abort_unless(array_key_exists($key, self::PREFERENCES), 422);

        $this->preferences[$key] = ! ($this->preferences[$key] ?? false);
        $this->profileSaved = false;
    }

    public function savePassword(): void
    {
        $user = request()->user();

        $this->validate([
            'currentPassword' => ['required', 'current_password'],
            'newPassword' => ['required', 'string', 'confirmed', 'different:currentPassword', new \App\Rules\StrongPassword([$user->name, $user->email, $user->byline])],
        ], [
            'currentPassword.current_password' => 'That isn’t your current password.',
            'newPassword.different' => 'That’s the password you have now. Choose a new one.',
            'newPassword.confirmed' => 'The two new passwords don’t match.',
        ], [
            'currentPassword' => 'current password',
            'newPassword' => 'new password',
        ]);

        $user->update(['password' => Hash::make($this->newPassword)]);

        // Anyone signed in elsewhere with the old password is signed out (you stay signed in here).
        $others = \App\Support\Devices::signOutOthers($user);
        ActivityLog::record('changed their password', 'people', 'user', (string) $user->id, $user->name);
        \App\Notifications\SecurityNotice::send($user, 'password', $user->name);

        $this->reset('currentPassword', 'newPassword', 'newPassword_confirmation');
        $this->passwordSaved = true;
        $this->stamp($others ? trans_choice('Password changed. Signed out of :count other device.|Password changed. Signed out of :count other devices.', $others) : 'Password changed.');
    }

    public function updatedStacyService(): void
    {
        $provider = $this->stacyService !== '' ? AiProvider::where('service', $this->stacyService)->first() : null;
        $this->stacyModel = (string) ($provider?->default_model ?? '');
        $this->stacySaved = false;
    }

    public function saveStacy(): void
    {
        $ready = AiProvider::where('enabled', true)->get()->filter->isReady()->pluck('service')->all();

        $this->validate([
            'stacyService' => ['nullable', 'in:'.implode(',', $ready ?: ['-'])],
            'stacyModel' => ['nullable', 'string', 'max:190'],
        ], [], ['stacyService' => 'AI service', 'stacyModel' => 'model']);

        $user = request()->user();
        $preferences = (array) $user->preferences;
        $preferences['stacy_service'] = $this->stacyService ?: null;
        $preferences['stacy_model'] = $this->stacyService && $this->stacyModel ? $this->stacyModel : null;
        $user->update(['preferences' => array_filter($preferences, fn ($v) => $v !== null)]);

        $this->stacySaved = true;
    }

    public function render()
    {
        return view('livewire.admin.account', [
            'user' => request()->user()->fresh(),
            'stickers' => rescue(fn () => \App\Support\Milestones::for(request()->user()), collect(), false),
            'options' => self::PREFERENCES,
            'aiServices' => AiProvider::where('enabled', true)->get()->filter->isReady()->values(),
            'naturalVoices' => \App\Stacy\Catalog::VOICES,
            'naturalService' => \App\Stacy\Speaker::provider()?->name(),
            'defaultStyle' => \App\Stacy\Catalog::SPEAKING_STYLE,
        ]);
    }
}
