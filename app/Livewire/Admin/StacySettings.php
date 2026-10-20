<?php

namespace App\Livewire\Admin;

use App\Models\ActivityLog;
use App\Models\AiProvider;
use App\Stacy\Catalog;
use App\Stacy\Providers\Provider;
use App\Stacy\StacyException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Settings › Stacy's brain: which AI services the book can use, their keys,
 * and the default model. People choose their own model in their account.
 */
#[Layout('components.layouts.admin')]
#[Title('Stacy’s brain')]
class StacySettings extends Component
{
    #[Url(as: 'service', except: 'anthropic')]
    public string $selected = 'anthropic';

    public bool $enabled = false;

    /** A newly typed key; blank means keep the saved one. */
    public string $apiKey = '';

    public string $baseUrl = '';

    public string $defaultModel = '';

    /** Result of the last "Fetch models" or "Test" press. */
    public ?string $notice = null;

    public bool $noticeIsError = false;

    public function mount(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);

        if (! array_key_exists($this->selected, Catalog::SERVICES)) {
            $this->selected = 'anthropic';
        }

        $this->load();
    }

    protected function record(): AiProvider
    {
        return AiProvider::firstOrNew(['service' => $this->selected]);
    }

    protected function load(): void
    {
        $record = $this->record();

        $this->enabled = (bool) $record->enabled;
        $this->apiKey = '';
        $this->baseUrl = (string) $record->base_url;
        $this->defaultModel = (string) $record->default_model;
        $this->notice = null;
        $this->resetValidation();
    }

    public function pick(string $service): void
    {
        abort_unless(array_key_exists($service, Catalog::SERVICES), 404);

        $this->selected = $service;
        $this->load();
    }

    public function save(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);

        $info = Catalog::get($this->selected);
        $record = $this->record();

        $this->validate([
            'apiKey' => ['nullable', 'string', 'max:500'],
            'baseUrl' => [$info['base_url'] === '' && $this->enabled ? 'required' : 'nullable', 'url', 'max:255'],
            'defaultModel' => [$this->enabled ? 'required' : 'nullable', 'string', 'max:190'],
        ], [
            'defaultModel.required' => 'Choose a model for Stacy to use. “Fetch models” lists what this service offers.',
        ], ['apiKey' => 'API key', 'baseUrl' => 'address', 'defaultModel' => 'model']);

        if ($this->enabled && $info['needs_key'] && $this->apiKey === '' && ! $record->api_key) {
            $this->addError('apiKey', 'This service needs an API key.');

            return;
        }

        $record->fill([
            'enabled' => $this->enabled,
            'base_url' => $this->baseUrl !== '' ? rtrim($this->baseUrl, '/') : null,
            'default_model' => $this->defaultModel !== '' ? $this->defaultModel : null,
        ]);

        if ($this->apiKey !== '') {
            $record->api_key = trim($this->apiKey);
        }

        // The first service switched on becomes the default.
        if ($this->enabled && ! AiProvider::where('is_default', true)->where('service', '!=', $this->selected)->exists()) {
            $record->is_default = true;
        }
        if (! $this->enabled) {
            $record->is_default = false;
        }

        $record->save();
        ActivityLog::record('changed Stacy’s brain:', 'settings', 'settings', 'stacy', $record->name());

        $this->apiKey = '';
        $this->notice = $this->enabled ? 'Saved. Stacy can use '.$record->name().'.' : 'Saved. '.$record->name().' is switched off.';
        $this->noticeIsError = false;
    }

    public function makeDefault(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);

        $record = $this->record();
        if (! $record->exists || ! $record->enabled) {
            $this->notice = 'Switch this service on and save it first.';
            $this->noticeIsError = true;

            return;
        }

        AiProvider::where('id', '!=', $record->id)->update(['is_default' => false]);
        $record->update(['is_default' => true]);
        $this->notice = $record->name().' is now the default for everyone who hasn’t chosen.';
        $this->noticeIsError = false;
    }

    public function forgetKey(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);

        $record = $this->record();
        if ($record->exists) {
            $record->forceFill(['api_key' => null])->save();
        }
        $this->notice = 'Key removed.';
        $this->noticeIsError = false;
    }

    /**
     * A provider configured from the form, without saving it, so "Fetch
     * models" and "Test" work before pressing Save.
     */
    protected function draftProvider(): AiProvider
    {
        $record = $this->record();
        $draft = $record->replicate();
        $draft->service = $this->selected;
        $draft->base_url = $this->baseUrl !== '' ? $this->baseUrl : null;
        $draft->api_key = $this->apiKey !== '' ? trim($this->apiKey) : $record->api_key;

        return $draft;
    }

    public function fetchModels(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);

        try {
            $models = Provider::for($this->draftProvider(), $this->defaultModel ?: 'none')->listModels();
        } catch (StacyException $e) {
            $this->notice = $e->getMessage();
            $this->noticeIsError = true;

            return;
        } catch (Throwable $e) {
            report($e);
            $this->notice = 'Couldn’t fetch the list: '.$e->getMessage();
            $this->noticeIsError = true;

            return;
        }

        $record = $this->record();
        $record->service = $this->selected;
        $record->fill(['models' => $models, 'models_fetched_at' => now()]);
        if (! $record->exists) {
            $record->enabled = false;
        }
        $record->save();

        if (($this->defaultModel === '' || ! in_array($this->defaultModel, $models, true)) && $models !== []) {
            $this->defaultModel = (string) Catalog::pickDefault($this->selected, $models);
        }

        $this->notice = count($models).' models found. Pick one below.';
        $this->noticeIsError = false;
    }

    public function test(): void
    {
        $this->resetErrorBag(); // clear the last try's message

        abort_unless(request()->user()->isAdmin(), 403);

        if ($this->defaultModel === '') {
            $this->addError('defaultModel', 'Choose a model to test.');

            return;
        }

        try {
            $provider = Provider::for($this->draftProvider(), $this->defaultModel);
            $conversation = $provider->begin('You are a friendly test. Reply with one short sentence.', [
                ['role' => 'user', 'text' => 'Say hello to the people of this book in under ten words.'],
            ]);
            $reply = $provider->send($conversation, []);
            $this->notice = '“'.($reply->text ?: '…').'” — it works.';
            $this->noticeIsError = false;
        } catch (StacyException $e) {
            $this->notice = $e->getMessage();
            $this->noticeIsError = true;
        } catch (Throwable $e) {
            report($e);
            $this->notice = 'The test failed: '.$e->getMessage();
            $this->noticeIsError = true;
        }
    }

    public function render()
    {
        $records = AiProvider::all()->keyBy('service');
        $record = $records->get($this->selected) ?? new AiProvider(['service' => $this->selected]);

        return view('livewire.admin.stacy-settings', [
            'services' => Catalog::SERVICES,
            'records' => $records,
            'info' => Catalog::get($this->selected),
            'record' => $record,
            'models' => $record->modelList(),
        ]);
    }
}
