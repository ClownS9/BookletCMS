<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Models\StacyMessage;
use App\Stacy\StacyConfig;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings › How Stacy behaves: answer length, creativity, tone, language,
 * house style, memory, what she may change, who can use her, a daily limit
 * and her natural voice. No code needed.
 */
#[Layout('components.layouts.admin')]
#[Title('How Stacy behaves')]
class StacyBehaviour extends Component
{
    use Stamps;

    // Answers
    public string $length = '1500';     // a preset, or 'custom'
    public int $customTokens = 1500;
    public string $temperature = '';
    public string $tone = 'friendly';
    public string $language = '';
    public string $instructions = '';

    // Thinking
    public int $memory = 20;
    public int $steps = 6;

    // Changes
    public bool $canChange = true;
    public bool $canPublish = true;
    public bool $canEdit = true;
    public bool $canDraft = true;
    public bool $canComments = true;

    public bool $canInserts = true;

    // Who and how much
    public string $who = 'everyone';
    public int $dailyLimit = 0;
    public bool $naturalVoice = true;
    public bool $showModel = true;

    public bool $greet = true;

    public bool $greetSound = true;

    public bool $greetAloud = true;

    public function mount(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->fillFrom(StacyConfig::all());
    }

    protected function fillFrom(array $c): void
    {
        $tokens = (int) $c['max_tokens'];
        $this->length = array_key_exists($tokens, StacyConfig::LENGTHS) ? (string) $tokens : 'custom';
        $this->customTokens = $tokens;
        $this->temperature = $c['temperature'] === null ? '' : (string) $c['temperature'];
        $this->tone = (string) $c['tone'];
        $this->language = (string) $c['language'];
        $this->instructions = (string) $c['instructions'];
        $this->memory = (int) $c['memory'];
        $this->steps = (int) $c['steps'];
        $this->canChange = (bool) $c['can_change'];
        $this->canPublish = (bool) $c['can_publish'];
        $this->canEdit = (bool) $c['can_edit'];
        $this->canDraft = (bool) $c['can_draft'];
        $this->canComments = (bool) $c['can_comments'];
        $this->canInserts = (bool) $c['can_inserts'];
        $this->who = (string) $c['who'];
        $this->dailyLimit = (int) $c['daily_limit'];
        $this->naturalVoice = (bool) $c['natural_voice'];
        $this->showModel = (bool) $c['show_model'];
        $this->greet = (bool) $c['greet'];
        $this->greetSound = (bool) $c['greet_sound'];
        $this->greetAloud = (bool) $c['greet_aloud'];
    }

    public function toggle(string $setting): void
    {
        abort_unless(in_array($setting, ['canChange', 'canPublish', 'canEdit', 'canDraft', 'canComments', 'canInserts', 'naturalVoice', 'showModel', 'greet', 'greetSound', 'greetAloud'], true), 422);
        $this->{$setting} = ! $this->{$setting};
    }

    public function save(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);

        $this->validate([
            'length' => ['required', Rule::in(array_merge(array_map('strval', array_keys(StacyConfig::LENGTHS)), ['custom']))],
            'customTokens' => ['required_if:length,custom', 'integer', 'min:200', 'max:16000'],
            'temperature' => ['nullable', Rule::in(array_keys(StacyConfig::CREATIVITY))],
            'tone' => ['required', Rule::in(array_keys(StacyConfig::TONES))],
            'language' => ['nullable', 'string', 'max:40'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'memory' => ['required', 'integer', 'min:2', 'max:60'],
            'steps' => ['required', 'integer', 'min:1', 'max:12'],
            'who' => ['required', Rule::in(['everyone', 'editors', 'admins'])],
            'dailyLimit' => ['required', 'integer', 'min:0', 'max:10000'],
        ], [], [
            'customTokens' => 'answer length',
            'dailyLimit' => 'daily limit',
        ]);

        StacyConfig::save([
            'max_tokens' => $this->length === 'custom' ? $this->customTokens : (int) $this->length,
            'temperature' => $this->temperature === '' ? null : (float) $this->temperature,
            'tone' => $this->tone,
            'language' => trim($this->language),
            'instructions' => trim($this->instructions),
            'memory' => $this->memory,
            'steps' => $this->steps,
            'can_change' => $this->canChange,
            'can_publish' => $this->canPublish,
            'can_edit' => $this->canEdit,
            'can_draft' => $this->canDraft,
            'can_comments' => $this->canComments,
            'can_inserts' => $this->canInserts,
            'who' => $this->who,
            'daily_limit' => $this->dailyLimit,
            'natural_voice' => $this->naturalVoice,
            'show_model' => $this->showModel,
            'greet' => $this->greet,
            'greet_sound' => $this->greetSound,
            'greet_aloud' => $this->greetAloud,
        ]);

        ActivityLog::record('changed how Stacy behaves', 'settings', 'settings', 'stacy', 'How Stacy behaves');
        $this->stamp('Saved. Stacy uses these from her next answer.');
    }

    public function resetToDefaults(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->fillFrom(StacyConfig::DEFAULTS);
        $this->resetValidation();
    }

    public function render()
    {
        $asked = fn ($since) => StacyMessage::query()->where('role', 'user')->where('created_at', '>=', $since);

        return view('livewire.admin.stacy-behaviour', [
            'tones' => StacyConfig::TONES,
            'lengths' => StacyConfig::LENGTHS,
            'creativity' => StacyConfig::CREATIVITY,
            // What switched-on inserts teach her, grouped by insert.
            'skills' => collect(rescue(fn () => app(\App\Inserts\InsertManager::class)->skills(), [], false))
                ->groupBy(fn ($s) => $s['insert']->name())
                ->map(fn ($group) => $group->pluck('skill')->all()),
            'usage' => [
                'today' => $asked(today())->count(),
                'week' => $asked(now()->subDays(7))->count(),
                'people' => \App\Models\StacyConversation::query()
                    ->whereHas('messages', fn ($q) => $q->where('role', 'user')->where('created_at', '>=', now()->subDays(7)))
                    ->distinct()->count('user_id'),
            ],
        ]);
    }
}
