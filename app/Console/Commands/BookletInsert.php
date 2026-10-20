<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * php artisan booklet:insert my-thing
 *
 * Makes a new insert in inserts/my-thing/ that already works: a page in the
 * book with a little list you can add to, a [[my-thing]] slip that shows the
 * list in a post, and a setting. Change it from there.
 */
class BookletInsert extends Command
{
    protected $signature = 'booklet:insert {name : A short name, like "reading-list"}';

    protected $description = 'Make a new insert (an extra for the book) in inserts/, ready to switch on';

    public function handle(): int
    {
        $key = Str::slug((string) $this->argument('name'));
        if (! preg_match('/^[a-z][a-z0-9-]{0,59}$/', $key)) {
            $this->error('Use letters, numbers and dashes, starting with a letter (like reading-list).');

            return self::FAILURE;
        }

        $root = rtrim((string) config('booklet.inserts_path', base_path('inserts')), '/');
        $dir = $root.'/'.$key;
        if (file_exists($dir)) {
            $this->error("There's already an insert at {$dir}.");

            return self::FAILURE;
        }

        $name = Str::headline($key);
        $slip = $key;
        @mkdir($dir.'/views', 0755, true);
        @mkdir($dir.'/assets', 0755, true);

        file_put_contents($dir.'/insert.php', <<<PHP
<?php

/*
 * {$name}: an insert for Booklet.
 *
 * Everything this insert adds is described here. Switch it on in
 * Settings › Inserts. Its views are in views/, its files (CSS, scripts,
 * pictures) in assets/.
 */

use App\Inserts\Insert;

return [
    'name' => '{$name}',
    'about' => 'A short list you keep in the admin and show in any post.',
    'version' => '1.0',
    'sticker' => 'sun',          // coral, sun, mint, sky, lilac, rose, sand or ink

    // Settings, drawn for you on the Inserts shelf: text, textarea, number, toggle, select, email, url.
    'settings' => [
        'heading' => ['type' => 'text', 'label' => 'Heading on the site', 'default' => '{$name}'],
    ],

    // Its page in the admin book. 'chapter' tucks it into one (Posts, Pages, Media, People, Comments…);
    // leave it out for a chapter of its own. The page's view is views/admin.blade.php.
    'page' => [
        'title' => '{$name}',
        'who' => ['admin', 'editor'],
        'form' => ['title' => ''],
        'data' => fn (Insert \$insert) => ['items' => \$insert->records('items')->all()],
        'actions' => [
            'add' => function (Insert \$insert, array \$form) {
                \$insert->validate(\$form, ['title' => 'required|string|max:200']);
                \$insert->records('items')->create(['title' => trim(\$form['title'])]);

                return ['form' => ['title' => ''], 'stamp' => 'Added.'];
            },
            'remove' => function (Insert \$insert, array \$form, \$id) {
                \$insert->records('items')->delete((int) \$id);

                return 'Removed.';
            },
        ],
    ],

    // [[{$slip}]] in a post or page shows the list.
    'slips' => [
        '{$slip}' => fn (string \$words, Insert \$insert) => \$insert->view('slip', ['items' => \$insert->records('items')->all()]),
    ],
    'slip_help' => ['{$slip}' => '[[{$slip}]]'],

    // Files the public site loads while it's on (from assets/).
    'site' => ['css' => ['{$key}.css']],

    // A card on the Contents page. Return null when there's nothing to say, and it stays hidden.
    'contents' => [
        'latest' => [
            'title' => 'On the {$name} list',
            'show' => fn (Insert \$insert) => (\$items = \$insert->records('items')->all())->isEmpty() ? null : [
                'count' => \$items->count(),
                'items' => \$items->take(3)->map(fn (\$i) => ['label' => \$i->field('title'), 'href' => route('admin.insert', \$insert->key)])->values()->all(),
            ],
        ],
    ],

    // What it teaches Stacy. 'look' answers a question; 'propose' + 'do' make a change
    // after the person says yes ('who' defaults to editors and admins for changes).
    'stacy' => [
        'list' => [
            'about' => 'List what is on the {$name} list.',  // for Stacy: when to use it
            'label' => 'Tells you what’s on the list',       // for people, on the shelf
            'look' => fn (array \$args, Insert \$insert) => ['items' => \$insert->records('items')->all()->map(fn (\$i) => \$i->field('title'))->values()->all()],
        ],
        'add' => [
            'about' => 'Propose adding something to the {$name} list.',
            'label' => 'Adds something to the list',
            'takes' => ['title' => ['type' => 'string', 'about' => 'What to add']],
            'needs' => ['title'],
            'propose' => fn (array \$args) => ['summary' => 'Add “'.trim(\$args['title']).'” to the {$name} list.', 'button' => 'Yes, add it', 'data' => ['title' => trim(\$args['title'])]],
            'do' => function (array \$data, Insert \$insert) {
                \$insert->records('items')->create(['title' => \$data['title']]);

                return 'Done. It’s on the list.';
            },
        ],
    ],

    // Things it can react to: entry.published, comment.received, message.received, subscriber.confirmed.
    'when' => [
        // 'entry.published' => fn (\$entry, Insert \$insert) => logger('Published: '.\$entry->title()),
    ],

    // Jobs to run on their own: everyFiveMinutes, hourly, daily or weekly.
    'every' => [
        // 'daily' => fn (Insert \$insert) => …,
    ],
];
PHP);

        file_put_contents($dir.'/views/admin.blade.php', <<<'BLADE'
<h2 class="heading">The list</h2>

<form wire:submit="act('add')" style="display: flex; gap: 8px; align-items: flex-start; flex-wrap: wrap">
    <div class="field" style="flex: 1 1 240px; margin: 0">
        <label for="item-title" class="sr-only">New item</label>
        <input id="item-title" type="text" wire:model="form.title" placeholder="Something to add">
        @error('form.title') <p class="field__error">{{ $message }}</p> @enderror
    </div>
    <button type="submit" class="btn btn--ink">Add</button>
</form>

@if ($items->isEmpty())
    <p class="empty-note">Nothing yet.</p>
@else
    <ul class="picks">
        @foreach ($items as $item)
            <li wire:key="item-{{ $item->id }}" style="display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--rule)">
                <span class="serif" style="font-size: 18px">{{ $item->field('title') }}</span>
                <button type="button" class="link-button" wire:click="act('remove', {{ $item->id }})" wire:confirm="Remove it?">Remove</button>
            </li>
        @endforeach
    </ul>
@endif
BLADE);

        file_put_contents($dir.'/views/slip.blade.php', <<<'BLADE'
<aside class="insert-list">
    <h2>{{ $insert->setting('heading') }}</h2>
    <ul>
        @foreach ($items as $item)
            <li>{{ $item->field('title') }}</li>
        @endforeach
    </ul>
</aside>
BLADE);

        file_put_contents($dir.'/assets/'.$key.'.css', ".insert-list { margin: 28px 0; padding: 20px 24px; border: 1px solid var(--rule); border-radius: 12px; }\n.insert-list h2 { font-family: var(--serif); font-weight: 400; font-size: 24px; margin-bottom: 8px; }\n");

        file_put_contents($dir.'/README.md', <<<MD
# {$name}

An insert for Booklet. Switch it on in Settings › Inserts.

- `insert.php` describes everything it adds.
- `views/` holds its Blade views: `admin` (its page in the book), `slip` (what `[[{$slip}]]` shows).
- `assets/` holds files the site can load (`\$insert->asset('file.css')`).
- `migrations/` (optional) for its own tables, run when it's switched on.

Its records are kept for it: `\$insert->records('items')->create([...])`, `->all()`, `->find(\$id)`, `->update(\$record, [...])`, `->delete(\$id)`.
MD);

        $this->info("Made inserts/{$key}. Switch it on in Settings › Inserts, then try [[{$slip}]] in a post.");

        return self::SUCCESS;
    }
}
