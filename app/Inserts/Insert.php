<?php

namespace App\Inserts;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One insert: a folder in inserts/ with an insert.php that describes it.
 * This is what an insert's own code is handed ($insert) to read its
 * settings, keep records, draw views and link to its files.
 */
class Insert
{
    /** Kinds of setting the shelf can draw a field for. */
    public const SETTING_TYPES = ['text', 'textarea', 'number', 'toggle', 'select', 'email', 'url'];

    protected ?array $state = null;

    public function __construct(
        public readonly string $key,
        public readonly string $path,
        protected array $manifest,
    ) {}

    // What it says about itself ---------------------------------------------------

    public function name(): string
    {
        return (string) ($this->manifest['name'] ?? Str::headline($this->key));
    }

    public function about(): string
    {
        return (string) ($this->manifest['about'] ?? '');
    }

    public function version(): string
    {
        return (string) ($this->manifest['version'] ?? '1.0');
    }

    public function author(): ?string
    {
        return $this->manifest['author'] ?? null;
    }

    /** The colour of its sticker: one of the book's tones. */
    public function sticker(): string
    {
        $tone = (string) ($this->manifest['sticker'] ?? 'sand');

        return in_array($tone, ['coral', 'sun', 'mint', 'sky', 'lilac', 'rose', 'sand', 'ink'], true) ? $tone : 'sand';
    }

    /** The Booklet version it needs (e.g. "1.0"), and whether this one is new enough. */
    public function needs(): ?string
    {
        return $this->manifest['needs'] ?? null;
    }

    public function fits(): bool
    {
        return ! $this->needs() || version_compare((string) config('booklet.version', '1.0'), (string) $this->needs(), '>=');
    }

    public function manifest(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->manifest, $key, $default);
    }

    // Switched on? ------------------------------------------------------------------

    protected function state(): array
    {
        if ($this->state === null) {
            $row = rescue(fn () => DB::table('inserts')->where('key', $this->key)->first(), null, false);
            $this->state = $row ? (array) $row : [];
        }

        return $this->state;
    }

    public function forgetState(): void
    {
        $this->state = null;
    }

    public function enabled(): bool
    {
        return (bool) ($this->state()['enabled'] ?? false);
    }

    public function tornOut(): bool
    {
        return ! empty($this->state()['torn_out_at']);
    }

    /** Switched on, not torn out, and this Booklet is new enough for it. */
    public function active(): bool
    {
        return $this->enabled() && ! $this->tornOut() && $this->fits();
    }

    public function error(): ?string
    {
        return $this->state()['error'] ?? null;
    }

    public function errorAt(): ?string
    {
        return $this->state()['error_at'] ?? null;
    }

    /** Where it was added from (null: put in the folder by hand). */
    public function source(): ?array
    {
        $source = json_decode((string) ($this->state()['source'] ?? ''), true);

        return is_array($source) && isset($source['type']) ? $source : null;
    }

    /** A newer version its source has (found by the daily check), if there is one. */
    public function updateReady(): ?string
    {
        $latest = $this->state()['latest_version'] ?? null;

        return $latest && version_compare((string) $latest, $this->version(), '>') ? (string) $latest : null;
    }

    public function checkedAt(): ?string
    {
        return $this->state()['latest_checked_at'] ?? null;
    }

    // Settings --------------------------------------------------------------------------

    /** @return array<string, array{type: string, label: string, default: mixed, hint: ?string, options: array, min: mixed, max: mixed}> */
    public function settingsSchema(): array
    {
        $schema = [];
        foreach ((array) ($this->manifest['settings'] ?? []) as $handle => $field) {
            if (! is_string($handle) || ! preg_match('/^[a-z][a-z0-9_]{0,40}$/', $handle)) {
                continue;
            }
            $field = is_array($field) ? $field : ['type' => 'text', 'default' => $field];
            $type = in_array($field['type'] ?? 'text', self::SETTING_TYPES, true) ? $field['type'] ?? 'text' : 'text';
            $schema[$handle] = [
                'type' => $type,
                'label' => (string) ($field['label'] ?? Str::headline($handle)),
                'default' => $field['default'] ?? ($type === 'toggle' ? false : ''),
                'hint' => $field['hint'] ?? null,
                'options' => (array) ($field['options'] ?? []),
                'min' => $field['min'] ?? null,
                'max' => $field['max'] ?? null,
            ];
        }

        return $schema;
    }

    public function settings(): array
    {
        $saved = json_decode((string) ($this->state()['settings'] ?? '[]'), true) ?: [];
        $values = [];
        foreach ($this->settingsSchema() as $handle => $field) {
            $values[$handle] = array_key_exists($handle, $saved) ? $saved[$handle] : $field['default'];
        }

        return $values;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings()[$key] ?? $default;
    }

    // Keeping things ----------------------------------------------------------------------

    /** Its own storage for one kind of record: $insert->records('albums'). */
    public function records(string $kind): Records
    {
        return new Records($this->key, $kind);
    }

    /** Check form values; problems show under the fields, as anywhere in the admin. */
    public function validate(array $data, array $rules, array $messages = [], array $names = []): array
    {
        $validator = validator($data, $rules, $messages, $names);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $field => $list) {
                $errors['form.'.$field] = $list;
            }
            throw ValidationException::withMessages($errors);
        }

        return $validator->validated();
    }

    // Drawing ---------------------------------------------------------------------------------

    /** One of its views (inserts/{key}/views/{name}.blade.php). */
    public function view(string $name, array $data = []): HtmlString
    {
        return new HtmlString(view('insert-'.$this->key.'::'.$name, $data + ['insert' => $this])->render());
    }

    public function hasView(string $name): bool
    {
        return view()->exists('insert-'.$this->key.'::'.$name);
    }

    /** The web address of one of its files (inserts/{key}/assets/…). */
    public function asset(string $file): string
    {
        $file = ltrim($file, '/');
        $full = $this->path.'/assets/'.$file;

        return route('inserts.asset', ['insert' => $this->key, 'path' => $file]).(is_file($full) ? '?v='.filemtime($full) : '');
    }

    /** Its page in the admin, if it has one. */
    public function page(): ?array
    {
        $page = $this->manifest['page'] ?? null;
        if (! is_array($page)) {
            return null;
        }

        return [
            'title' => (string) ($page['title'] ?? $this->name()),
            'chapter' => (string) ($page['chapter'] ?? ''),
            'who' => (array) ($page['who'] ?? ['admin', 'editor']),
            'view' => (string) ($page['view'] ?? 'admin'),
            'left' => $page['left'] ?? 'admin-left',
            'data' => $page['data'] ?? null,
            'actions' => (array) ($page['actions'] ?? []),
            'form' => (array) ($page['form'] ?? []),
        ];
    }

    public function pageUrl(): ?string
    {
        return $this->page() ? route('admin.insert', $this->key) : null;
    }

    public function canOpen(?\App\Models\User $user): bool
    {
        return $user && $this->page() && in_array($user->level(), $this->page()['who'], true);
    }

    /** @return array<string, callable> [[name: …]] pieces it adds to writing */
    public function slips(): array
    {
        return array_filter((array) ($this->manifest['slips'] ?? []), fn ($slip, $name) => is_callable($slip) && preg_match('/^[a-z][a-z0-9_-]{0,40}$/', (string) $name), ARRAY_FILTER_USE_BOTH);
    }

    /** How to use its slips, in a line each (for the shelf and for Stacy). */
    public function slipHelp(): array
    {
        $help = (array) ($this->manifest['slip_help'] ?? []);
        $lines = [];
        foreach (array_keys($this->slips()) as $name) {
            $lines[$name] = (string) ($help[$name] ?? "[[{$name}]]");
        }

        return $lines;
    }

    /** Kinds of thing a Stacy skill can be handed. */
    public const SKILL_TYPES = ['string' => 'string', 'text' => 'string', 'integer' => 'integer', 'number' => 'number', 'boolean' => 'boolean', 'list' => 'array'];

    /**
     * What it teaches Stacy ('stacy' => [name => …] in insert.php). A skill
     * either looks something up ('look') or offers a change ('propose'),
     * which only happens ('do') after the person says yes.
     *
     * @return array<string, array{name: string, tool: string, about: string, label: string, takes: array, needs: array, who: array, changes: bool, look: ?callable, propose: ?callable, do: ?callable}>
     */
    public function skills(): array
    {
        $skills = [];
        foreach ((array) ($this->manifest['stacy'] ?? []) as $name => $skill) {
            $tool = $this->key.'__'.$name;
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name) || strlen($tool) > 64 || ! is_array($skill)) {
                continue;
            }
            $changes = isset($skill['propose']) || isset($skill['do']);
            if ($changes ? ! (is_callable($skill['propose'] ?? null) && is_callable($skill['do'] ?? null)) : ! is_callable($skill['look'] ?? null)) {
                continue; // a change needs both 'propose' and 'do'; a look-up needs 'look'
            }

            $takes = [];
            foreach ((array) ($skill['takes'] ?? []) as $arg => $spec) {
                if (! is_string($arg) || ! preg_match('/^[a-z][a-z0-9_]{0,40}$/', $arg)) {
                    continue;
                }
                $spec = is_array($spec) ? $spec : ['about' => (string) $spec];
                $type = self::SKILL_TYPES[$spec['type'] ?? 'string'] ?? 'string';
                $takes[$arg] = array_filter([
                    'type' => $type,
                    'description' => isset($spec['about']) ? (string) $spec['about'] : null,
                    'enum' => isset($spec['options']) ? array_values(array_map('strval', (array) $spec['options'])) : null,
                    'items' => $type === 'array' ? ['type' => 'string'] : null,
                ], fn ($v) => $v !== null);
            }

            $skills[$name] = [
                'name' => $name,
                'tool' => $tool,
                'about' => trim((string) ($skill['about'] ?? Str::headline($name))),
                'label' => trim((string) ($skill['label'] ?? $skill['about'] ?? Str::headline($name))), // for people, on the shelf
                'takes' => $takes,
                'needs' => array_values(array_intersect((array) ($skill['needs'] ?? []), array_keys($takes))),
                'who' => (array) ($skill['who'] ?? ($changes ? ['admin', 'editor'] : ['admin', 'editor', 'writer'])),
                'changes' => $changes,
                'look' => $changes ? null : $skill['look'],
                'propose' => $changes ? $skill['propose'] : null,
                'do' => $changes ? $skill['do'] : null,
            ];
        }

        return $skills;
    }

    /**
     * Cards it puts on the Contents page ('contents' => [name => …]).
     *
     * @return array<string, array{name: string, title: string, who: array, show: callable}>
     */
    public function cards(): array
    {
        $cards = [];
        foreach ((array) ($this->manifest['contents'] ?? []) as $name => $card) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_-]{0,40}$/', $name) || ! is_array($card) || ! is_callable($card['show'] ?? null)) {
                continue;
            }
            $cards[$name] = [
                'name' => $name,
                'title' => trim((string) ($card['title'] ?? $this->name())),
                'who' => (array) ($card['who'] ?? $this->page()['who'] ?? ['admin', 'editor', 'writer']),
                'show' => $card['show'],
            ];
        }

        return $cards;
    }

    /**
     * Cut-outs it adds to blocks fields ('blocks' => [name => …] in insert.php):
     *
     *   'upcoming' => [
     *       'name' => 'Upcoming events', 'about' => 'The next few events.', 'tone' => 'mint',
     *       'fields' => ['count' => ['type' => 'select', 'display' => 'How many', 'options' => ['3' => '3', '5' => '5']]],
     *       'show' => fn (array $block, Insert $insert, array $context) => $insert->view('slip', …),
     *   ],
     *
     * Field types: text, textarea, markdown, select, toggle, link, number, picture, pictures.
     * A select's 'options' may be a function, worked out when the editor opens.
     *
     * @return array<string, array{name: string, about: string, tone: string, fields: array<string, array>, show: callable}>
     */
    public function blocks(): array
    {
        $tones = ['sun', 'mint', 'sky', 'lilac', 'rose', 'coral', 'sand'];
        $blocks = [];
        foreach ((array) ($this->manifest['blocks'] ?? []) as $name => $block) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_-]{0,40}$/', $name) || ! is_array($block) || ! is_callable($block['show'] ?? null)) {
                continue;
            }
            $fields = [];
            foreach ((array) ($block['fields'] ?? []) as $handle => $field) {
                $field = is_array($field) ? $field : ['type' => 'text', 'display' => (string) $field];
                $type = (string) ($field['type'] ?? 'text');
                if (! is_string($handle) || ! preg_match('/^[a-z][a-z0-9_]{0,40}$/', $handle) || in_array($handle, ['id', 'type', 'enabled'], true)
                    || ! in_array($type, \App\Support\Blocks::FIELD_TYPES, true)) {
                    continue;
                }
                $field = ['type' => $type, 'display' => (string) ($field['display'] ?? Str::headline($handle))]
                    + array_intersect_key($field, array_flip(['options', 'default', 'instructions']));
                if (isset($field['options']) && is_callable($field['options'])) {
                    $choices = $field['options'];
                    $field['options'] = fn () => $choices($this); // handed the insert, like everything else
                }
                $fields[$handle] = $field;
            }
            $blocks[$name] = [
                'name' => trim((string) ($block['name'] ?? Str::headline($name))),
                'about' => trim((string) ($block['about'] ?? $this->name())),
                'tone' => in_array($block['tone'] ?? null, $tones, true) ? $block['tone'] : $this->stickerTone(),
                'fields' => $fields,
                'show' => $block['show'],
            ];
        }

        return $blocks;
    }

    /** Its sticker as one of the named tones, for its cut-outs. */
    protected function stickerTone(): string
    {
        $sticker = $this->sticker();

        return in_array($sticker, ['sun', 'mint', 'sky', 'lilac', 'rose', 'coral', 'sand'], true) ? $sticker : 'sand';
    }

    /** @return array{css: string[], js: string[]} files the public site loads while it's on */
    public function siteAssets(): array
    {
        $site = (array) ($this->manifest['site'] ?? []);

        return ['css' => array_values((array) ($site['css'] ?? [])), 'js' => array_values((array) ($site['js'] ?? []))];
    }
}
