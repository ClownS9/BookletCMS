<?php

namespace App\Models;

use App\Stacy\Catalog;
use Illuminate\Database\Eloquent\Model;

/**
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property string $service
 * @property bool $enabled
 * @property bool $is_default
 * @property string|null $api_key
 * @property string|null $base_url
 * @property string|null $default_model
 * @property array|null $models
 * @property \Illuminate\Support\Carbon|null $models_fetched_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class AiProvider extends Model
{
    protected $fillable = ['service', 'enabled', 'is_default', 'api_key', 'base_url', 'default_model', 'models', 'models_fetched_at'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'is_default' => 'boolean',
            'api_key' => \App\Casts\Secret::class,
            'models' => 'array',
            'models_fetched_at' => 'datetime',
        ];
    }

    /**
     * The catalog entry: name, kind, default base URL, where to get a key.
     */
    public function info(): array
    {
        return Catalog::get($this->service);
    }

    public function name(): string
    {
        return $this->info()['name'];
    }

    public function baseUrl(): string
    {
        return rtrim($this->base_url ?: (string) $this->info()['base_url'], '/');
    }

    public function isReady(): bool
    {
        return $this->enabled
            && ($this->api_key || ! $this->info()['needs_key'])
            && $this->baseUrl() !== '';
    }

    /** Saved before APP_KEY changed, so it can't be read any more. */
    public function keyLost(): bool
    {
        return \App\Casts\Secret::lost($this, 'api_key');
    }

    /**
     * "••••1234", so admins can tell which key is saved without seeing it.
     */
    public function maskedKey(): ?string
    {
        return $this->api_key ? '••••'.substr($this->api_key, -4) : null;
    }

    /**
     * @return string[]
     */
    public function modelList(): array
    {
        $models = (array) ($this->models ?? []);

        if ($models === [] && $this->info()['suggested_models'] !== []) {
            $models = $this->info()['suggested_models'];
        }

        if ($this->default_model && ! in_array($this->default_model, $models, true)) {
            array_unshift($models, $this->default_model);
        }

        return array_values(array_unique($models));
    }
}
