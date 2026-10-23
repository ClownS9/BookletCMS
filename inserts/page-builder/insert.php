<?php

/*
 * Page builder: pages made of stacked sections (a hero, cards, prices,
 * questions…), built on the page itself. Signed in, open a built page and
 * press "Edit this page": click any words to type into them, add, move and
 * dress sections, and publish, all where readers will see it. Each page has
 * its own address (/offer), can be the home page, and can be dropped into
 * any post or page with [[builder: offer]].
 *
 * Pages keep a draft and what's published: readers only ever see what was
 * published, and the draft can be put back to it.
 *
 * Works on Booklet 1.1. On Booklet 1.2 a page can also be the home page,
 * and built pages are in the sitemap and the plain-file copy.
 */

use App\Booklet\Facades\Booklet;
use App\Inserts\Insert;
use App\Models\InsertRecord;
use BookletInserts\PageBuilder\Builder;
use BookletInserts\PageBuilder\Fields;

require_once __DIR__.'/src/Builder.php';
require_once __DIR__.'/src/Fields.php';

$builder = new Builder(require __DIR__.'/sections.php');

// Pictures come from Media only.
Fields::$pictures = function (string $path): ?array {
    static $seen = [];
    if (! array_key_exists($path, $seen)) {
        $asset = rescue(fn () => Booklet::assets()->find(['container' => 'media', 'path' => $path]), null, false);
        $seen[$path] = $asset && $asset->isImage() ? \App\Support\Blocks::picture($path) : null;
    }

    return $seen[$path];
};
$isPicture = fn (string $path): bool => Fields::pictureFor($path) !== null;

/** Booklet 1.2 lets an insert make the home page. */
$homeWorks = fn (): bool => version_compare((string) config('booklet.version', '1.0'), '1.2', '>=');

/** The blog's categories, for "Latest posts". */
$categories = fn (): array => ['' => 'Every category'] + rescue(fn () => Booklet::terms()->whereTaxonomy('categories')
    ->mapWithKeys(fn ($t) => [(string) $t->slug() => (string) $t->title()])->all(), [], false);

/** Where a page stands: [draft|changed|live, words]. */
$status = function (InsertRecord $page): array {
    $published = $page->field('published');
    if ($published === null) {
        return ['draft', 'Not on the site yet'];
    }
    if ($page->field('draft') != $published) {
        return ['changed', 'Changes not published'];
    }

    return ['live', 'On the site'];
};

/** Its address for readers. */
$addressOf = fn (InsertRecord $page): string => $page->field('home') && $homeWorks() ? '/' : '/'.$page->slug;

/** Addresses Booklet itself (or something else on the site) already answers. */
$taken = function (string $address, ?int $except = null): ?string {
    if ($address === '') {
        return 'Give it an address, like offer or about/team.';
    }
    $first = explode('/', $address)[0];
    $blog = trim((string) rescue(fn () => app(\App\Booklet\Site::class)->blogPath(), '/blog', false), '/');
    if (in_array($first, ['admin', 'livewire', 'login', 'logout', 'setup', 'preview', 'proof', 'storage', 'subscribe', 'unsubscribe', 'up', 'api',
        'search', 'page-builder', 'themes', 'inserts', 'booklet', 'vendor', 'build', 'password', 'two-factor', 'feed.xml', 'sitemap.xml', 'robots.txt'], true)
        || ($blog !== '' && $first === explode('/', $blog)[0])) {
        return '/'.$address.' is used by Booklet itself. Choose another address.';
    }
    $other = InsertRecord::query()->where('insert', 'page-builder')->where('kind', 'pages')->where('slug', $address)
        ->when($except, fn ($q) => $q->whereKeyNot($except))->exists();
    if ($other) {
        return 'Another built page has /'.$address.' already.';
    }
    $site = app(\App\Booklet\Site::class);
    $entry = rescue(fn () => $site->published()->first(fn ($e) => trim((string) $e->uri(), '/') === $address), null, false)
        ?? rescue(fn () => Booklet::terms()->all()->first(fn ($t) => trim((string) $t->attribute('uri'), '/') === $address), null, false);
    if ($entry) {
        return 'A post or page is at /'.$address.' already.';
    }
    try {
        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/'.$address));
        if (! $route->isFallback && ! str_starts_with((string) $route->getName(), 'site.insert.page-builder.page.')) {
            return '/'.$address.' is taken by another part of the site.';
        }
    } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
        // Nothing answers there: free.
    }

    return null;
};

/**
 * One page for readers (what's published), or the draft. People who can
 * edit it also get the "Edit this page" button, and the editor.
 */
$show = function (Insert $insert, InsertRecord $page, bool $draft = false, bool $editable = true) use ($builder, $homeWorks) {
    $user = request()->user();

    return view('insert-page-builder::page', [
        'insert' => $insert,
        'page' => $page,
        'title' => (string) $page->field('title'),
        'description' => trim((string) $page->field('description')) ?: null,
        'sections' => $builder->clean($page->field($draft ? 'draft' : 'published')),
        'types' => $builder->types(),
        'draft' => $draft,
        'home' => $page->field('home') && $homeWorks(),
        'editable' => $editable && $insert->canOpen($user),
    ]);
};

/**
 * Change a page's draft, keeping the one before for Undo. Typing into the
 * same words keeps adding to one Undo step.
 */
$change = function (Insert $insert, InsertRecord $page, callable $edit, ?string $mark = null) use ($builder): array {
    $before = $builder->clean($page->field('draft'));
    $after = array_values($edit($before));
    if ($after == $before) {
        return $after;
    }
    $history = (array) $page->field('history', []);
    $last = (array) $page->field('history_mark', []);
    $same = $mark !== null && ($last['mark'] ?? null) === $mark && now()->timestamp - (int) ($last['at'] ?? 0) < 30;
    if (! $same) {
        $history[] = $before;
        $history = array_slice($history, -30);
    }
    $insert->records('pages')->update($page, [
        'draft' => $after,
        'history' => $history,
        'history_mark' => ['mark' => $mark, 'at' => now()->timestamp],
        'edited_at' => now()->toIso8601String(),
    ]);

    return $after;
};

/** Find a section in a page's draft: [index, section], or [null, null]. */
$locate = function (array $sections, mixed $id): array {
    foreach ($sections as $i => $s) {
        if ($s['id'] === $id) {
            return [$i, $s];
        }
    }

    return [null, null];
};

/** Run a change to one section, saying plainly when it can't be done. */
$onSection = function (Insert $insert, $editing, $id, callable $edit, ?string $mark = null) use ($change, $locate) {
    $page = $insert->records('pages')->find($editing);
    if (! $page) {
        return null;
    }
    try {
        $change($insert, $page, function (array $sections) use ($id, $edit, $locate) {
            [$i, $s] = $locate($sections, $id);
            if ($i === null) {
                throw new InvalidArgumentException('That section isn’t on the page any more.');
            }
            $sections[$i] = $edit($s);

            return $sections;
        }, $mark);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
};

/*
 * What the editor can do. Each one is given the editor's state (which
 * section is chosen, which tab is open…) as $form, what was pressed as
 * $payload, and the page's id; it answers with words to show, or
 * ['form' => the new state, 'stamp' => words].
 */
$actions = [
    // Pages ----------------------------------------------------------------------------
    'create' => function (Insert $insert, array $form) use ($builder, $taken) {
        $data = $insert->validate($form, ['title' => 'required|string|max:120', 'starter' => 'nullable|string'], [], ['title' => 'page name']);
        $address = Builder::address($data['title']);
        // offer, offer-2…: a free address to start with (it can be changed under Page).
        for ($n = 2, $try = $address; $taken($try) !== null && $n < 50; $n++) {
            $try = $address.'-'.$n;
        }
        if ($why = $taken($try)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['form.title' => $why]);
        }
        $sections = $builder->starter((string) ($data['starter'] ?? 'blank'));
        if ($sections !== [] && $sections[0]['type'] === 'hero') {
            $sections[0]['fields']['title'] = trim($data['title']);
        }
        $page = $insert->records('pages')->create([
            'title' => trim($data['title']), 'description' => '', 'draft' => $sections, 'published' => null,
            'home' => false, 'history' => [], 'edited_at' => now()->toIso8601String(),
        ], $try);

        return ['editing' => $page->id, 'form' => ['title' => '', 'starter' => 'landing'], 'stamp' => 'Made. Opening it…'];
    },
    'delete' => function (Insert $insert, array $form, $id) {
        $page = $insert->records('pages')->find((int) $id);
        if (! $page) {
            return null;
        }
        $insert->records('pages')->delete($page);

        return ['editing' => null, 'stamp' => 'Page deleted.'];
    },
    'details' => function (Insert $insert, array $form, $payload, $editing) use ($taken) {
        $page = $insert->records('pages')->find($editing);
        if (! $page) {
            return null;
        }
        $data = $insert->validate((array) $payload, ['name' => 'required|string|max:120', 'address' => 'required|string|max:160', 'description' => 'nullable|string|max:300'],
            [], ['name' => 'page name', 'description' => 'description']);
        $address = Builder::address($data['address']);
        if ($why = $taken($address, $page->id)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['form.address' => $why]);
        }
        $old = $page->slug;
        $moved = $address !== $old && $page->field('published') !== null;
        $insert->records('pages')->update($page, ['title' => trim($data['name']), 'description' => trim((string) ($data['description'] ?? ''))]);
        $page->forceFill(['slug' => $address])->save();
        if ($moved) {
            // Links to the old address keep working.
            rescue(fn () => \App\Support\Redirects::moved('/'.$old, '/'.$address), null, false);
        }

        return ['stamp' => $moved ? 'Saved. /'.$old.' now sends readers to /'.$address.'.' : 'Saved.', 'moved' => $address !== $old ? '/'.$address : null];
    },
    'home' => function (Insert $insert, array $form, $on, $editing) use ($homeWorks) {
        if (! request()->user()?->isAdmin()) {
            return 'Only an admin can choose the home page.';
        }
        if (! $homeWorks()) {
            return 'A built page can be the home page from Booklet 1.2.';
        }
        $page = $insert->records('pages')->find($editing);
        if (! $page) {
            return null;
        }
        foreach ($insert->records('pages')->all() as $p) {
            if ($p->field('home') && $p->id !== $page->id) {
                $insert->records('pages')->update($p, ['home' => false]);
            }
        }
        $insert->records('pages')->update($page, ['home' => (bool) $on]);
        if (! $on) {
            return 'The home page is Booklet’s own again.';
        }

        return $page->field('published') !== null ? 'It’s the home page now.' : 'It becomes the home page when you publish it.';
    },
    'publish' => function (Insert $insert, array $form, $payload, $editing) use ($builder, $addressOf) {
        $page = $insert->records('pages')->find($editing);
        if (! $page) {
            return null;
        }
        $first = $page->field('published') === null;
        $insert->records('pages')->update($page, ['published' => $builder->clean($page->field('draft')), 'published_at' => now()->toIso8601String()]);
        \App\Models\ActivityLog::record($first ? 'published the built page' : 'updated the built page', 'publishing', 'page-builder', (string) $page->id, (string) $page->field('title'));

        return $first ? 'It’s on the site at '.$addressOf($page).'.' : 'Published. Readers see the new version now.';
    },
    'discard' => function (Insert $insert, array $form, $payload, $editing) use ($change) {
        $page = $insert->records('pages')->find($editing);
        if (! $page || $page->field('published') === null) {
            return null;
        }
        $change($insert, $page, fn () => (array) $page->field('published'));

        return 'Back to what’s on the site. (Undo brings your changes back.)';
    },
    'unpublish' => function (Insert $insert, array $form, $payload, $editing) {
        $page = $insert->records('pages')->find($editing);
        if (! $page) {
            return null;
        }
        $insert->records('pages')->update($page, ['published' => null, 'home' => false]);

        return 'Taken off the site. Only people who can edit it see it now.';
    },

    // Choosing -------------------------------------------------------------------------
    'select' => fn (Insert $insert, array $form, $id) => ['form' => array_merge($form, ['section' => is_string($id) ? $id : null, 'picking' => null, 'adding' => false, 'tab' => 'section'])],
    'tab' => fn (Insert $insert, array $form, $tab) => ['form' => array_merge($form, ['tab' => in_array($tab, ['section', 'outline', 'page'], true) ? $tab : 'section', 'picking' => null, 'adding' => false])],
    'adding' => fn (Insert $insert, array $form, $after) => ['form' => array_merge($form, ['adding' => $after !== false && $after !== null, 'section' => is_string($after) ? $after : ($form['section'] ?? null), 'picking' => null])],

    // Sections -------------------------------------------------------------------------
    'add' => function (Insert $insert, array $form, $type, $editing) use ($builder, $change, $locate) {
        $page = $insert->records('pages')->find($editing);
        if (! $page || ! is_string($type) || ! $builder->type($type)) {
            return null;
        }
        if (count($builder->clean($page->field('draft'))) >= Builder::MAX_SECTIONS) {
            return 'That’s as many sections as a page can hold.';
        }
        $new = $builder->make($type);
        $change($insert, $page, function (array $sections) use ($new, $form, $locate) {
            [$at] = $locate($sections, $form['section'] ?? null);
            array_splice($sections, $at === null ? count($sections) : $at + 1, 0, [$new]);

            return $sections;
        });

        return ['form' => array_merge($form, ['section' => $new['id'], 'adding' => false, 'picking' => null, 'tab' => 'section'])];
    },
    'move' => function (Insert $insert, array $form, $payload, $editing) use ($change, $locate) {
        $page = $insert->records('pages')->find($editing);
        if (! $page || ! is_array($payload)) {
            return null;
        }
        $change($insert, $page, function (array $sections) use ($payload, $locate) {
            [$from, $s] = $locate($sections, $payload['id'] ?? null);
            if ($from === null) {
                return $sections;
            }
            $to = isset($payload['to']) ? (int) $payload['to'] : $from + (int) ($payload['step'] ?? 0);
            $to = max(0, min(count($sections) - 1, $to));
            array_splice($sections, $from, 1);
            array_splice($sections, $to, 0, [$s]);

            return $sections;
        });

        return null;
    },
    'copy' => function (Insert $insert, array $form, $id, $editing) use ($builder, $change, $locate) {
        $page = $insert->records('pages')->find($editing);
        if (! $page || count($builder->clean($page->field('draft'))) >= Builder::MAX_SECTIONS) {
            return null;
        }
        $made = null;
        $change($insert, $page, function (array $sections) use ($id, $builder, $locate, &$made) {
            [$i, $s] = $locate($sections, $id);
            if ($i !== null) {
                $made = $builder->copy($s);
                array_splice($sections, $i + 1, 0, [$made]);
            }

            return $sections;
        });

        return $made ? ['form' => array_merge($form, ['section' => $made['id']])] : null;
    },
    'remove' => function (Insert $insert, array $form, $id, $editing) use ($change, $locate) {
        $page = $insert->records('pages')->find($editing);
        if (! $page) {
            return null;
        }
        $next = null;
        $change($insert, $page, function (array $sections) use ($id, $locate, &$next) {
            [$i] = $locate($sections, $id);
            if ($i !== null) {
                array_splice($sections, $i, 1);
                $next = $sections[min($i, count($sections) - 1)]['id'] ?? null;
            }

            return $sections;
        });

        return ['form' => array_merge($form, ['section' => $next]), 'stamp' => 'Taken out. (Undo puts it back.)'];
    },

    // Words typed on the page (or in a box in the drawer), and every other field.
    'set' => function (Insert $insert, array $form, $payload, $editing) use ($builder, $onSection, $isPicture, $categories) {
        if (! is_array($payload) || ! is_string($payload['field'] ?? null)) {
            return null;
        }
        $field = $payload['field'];
        $choices = str_ends_with($field, 'category') ? $categories() : [];

        return $onSection($insert, $editing, $payload['id'] ?? null,
            fn (array $s) => $builder->set($s, $field, $payload['value'] ?? '', $isPicture, $choices),
            // A run of typing into the same words is one Undo step.
            'set:'.($payload['id'] ?? '').':'.$field);
    },
    'look' => function (Insert $insert, array $form, $payload, $editing) use ($builder, $onSection, $isPicture) {
        if (! is_array($payload) || ! is_string($payload['key'] ?? null)) {
            return null;
        }

        return $onSection($insert, $editing, $payload['id'] ?? null, fn (array $s) => $builder->setLook($s, $payload['key'], $payload['value'] ?? '', $isPicture));
    },
    'item' => function (Insert $insert, array $form, $payload, $editing) use ($builder, $onSection) {
        if (! is_array($payload) || ! in_array($payload['do'] ?? '', ['add', 'move', 'remove'], true)) {
            return null;
        }

        return $onSection($insert, $editing, $payload['id'] ?? null,
            fn (array $s) => $builder->items($s, (string) ($payload['field'] ?? ''), $payload['do'], (int) ($payload['at'] ?? -1), (int) ($payload['step'] ?? 0)));
    },

    // Choosing pictures from Media.
    'pick' => function (Insert $insert, array $form, $payload) {
        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || ! is_string($payload['field'] ?? null)) {
            return null;
        }

        return ['form' => array_merge($form, ['section' => $payload['id'], 'tab' => 'section', 'adding' => false,
            'picking' => ['id' => $payload['id'], 'field' => $payload['field']]])];
    },
    'unpick' => fn (Insert $insert, array $form) => ['form' => array_merge($form, ['picking' => null])],
    'choose' => function (Insert $insert, array $form, $path, $editing) use ($builder, $onSection, $isPicture) {
        $picking = $form['picking'] ?? null;
        if (! is_array($picking) || ! is_string($path)) {
            return null;
        }
        $many = false;
        $why = $onSection($insert, $editing, $picking['id'] ?? null, function (array $s) use ($builder, $picking, $path, $isPicture, &$many) {
            if ($picking['field'] === 'look.picture') {
                return $builder->setLook($s, 'picture', $path, $isPicture);
            }
            $many = ($builder->field($s['type'], (string) $picking['field'])['kind'] ?? '') === 'pictures';
            if ($many) {
                // Several: press to add or take out.
                $list = (array) data_get($s['fields'], $picking['field'], []);
                $path = in_array($path, $list, true) ? array_values(array_diff($list, [$path])) : [...$list, $path];
            }

            return $builder->set($s, (string) $picking['field'], $path, $isPicture);
        });
        if ($why) {
            return $why;
        }

        return $many ? null : ['form' => array_merge($form, ['picking' => null])];
    },

    'undo' => function (Insert $insert, array $form, $payload, $editing) use ($builder, $locate) {
        $page = $insert->records('pages')->find($editing);
        $history = (array) $page?->field('history', []);
        if (! $page || $history === []) {
            return 'Nothing to undo.';
        }
        $back = $builder->clean(array_pop($history));
        $insert->records('pages')->update($page, ['draft' => $back, 'history' => $history, 'history_mark' => []]);
        [$i] = $locate($back, $form['section'] ?? null);

        return ['form' => array_merge($form, ['section' => $i === null ? ($back[0]['id'] ?? null) : $form['section'], 'picking' => null])];
    },
];

/** The editor's state as the page sent it, made safe. */
$stateFrom = fn (array $in): array => [
    'section' => is_string($in['section'] ?? null) && preg_match('/^[a-z0-9]{4,16}$/', $in['section']) ? $in['section'] : null,
    'tab' => in_array($in['tab'] ?? null, ['section', 'outline', 'page'], true) ? $in['tab'] : 'section',
    'adding' => (bool) ($in['adding'] ?? false),
    'picking' => is_array($in['picking'] ?? null) && is_string($in['picking']['id'] ?? null) && is_string($in['picking']['field'] ?? null)
        ? ['id' => $in['picking']['id'], 'field' => $in['picking']['field']] : null,
];

/** Everything the editor on the page needs to draw itself again. */
$respond = function (Insert $insert, InsertRecord $page, array $state, ?string $stamp = null, bool $quiet = false, array $errors = [], array $extra = [])
    use ($builder, $status, $categories, $homeWorks, $addressOf) {
    $page = $page->fresh() ?? $page;
    $sections = $builder->clean($page->field('draft'));
    if ($state['section'] !== null && ! collect($sections)->contains('id', $state['section'])) {
        $state['section'] = null;
    }
    $selected = collect($sections)->firstWhere('id', $state['section']);
    [$where, $words] = $status($page);

    return response()->json([
        'stamp' => $stamp,
        'errors' => $errors,
        'state' => $state,
        'bar' => [
            'name' => (string) $page->field('title'),
            'status' => $where,
            'words' => $words,
            'undoable' => count((array) $page->field('history', [])) > 0,
            'address' => $addressOf($page),
        ],
        'drawer' => $insert->view('drawer', [
            'page' => $page, 'sections' => $sections, 'selected' => $selected, 'state' => $state,
            'types' => $builder->types(), 'looks' => $builder->looks(), 'builder' => $builder, 'errors' => $errors,
            'status' => [$where, $words],
            'categories' => $selected && $selected['type'] === 'posts' ? $categories() : [],
            'canHome' => (bool) request()->user()?->isAdmin() && $homeWorks(),
            'pictures' => $state['picking'] ? Booklet::assets()->inContainer('media')->filter(fn ($a) => $a->isImage())
                ->mapWithKeys(fn ($a) => [$a->path() => [
                    'thumb' => \App\Support\Pictures::srcset($a->path()) !== '' ? \App\Support\Pictures::url($a->path(), 320) : \App\Support\Disk::of('public')->url($a->path()),
                    'alt' => (string) $a->get('alt', ''),
                ]])->all() : [],
        ])->toHtml(),
        // The page's sections as they are now, ready to type into (left out while typing: they're on the page already).
        'page' => $quiet ? null : $insert->view('sections', ['sections' => $sections, 'types' => $builder->types(), 'live' => true, 'embedded' => false])->toHtml(),
    ] + $extra)->header('Cache-Control', 'no-store, private');
};

return [
    'key' => 'page-builder',
    'name' => 'Page builder',
    'about' => 'Pages made of stacked sections (a hero, cards, prices, questions…), built right on the page itself. Each has its own address, can be the home page, and fits into any post with [[builder: …]].',
    'version' => '1.0',
    'author' => 'Booklet',
    'sticker' => 'lilac',
    'needs' => '1.1',

    // In the book: the list of built pages, and making a new one. Building happens on the page.
    'page' => [
        'title' => 'Built pages',
        'chapter' => 'Pages',
        'left' => 'admin-left',
        'view' => 'admin',
        'form' => ['title' => '', 'starter' => 'landing'],

        'data' => fn (Insert $insert, array $form, $editing) => [
            'pages' => $insert->records('pages')->all(),
            'made' => $editing ? $insert->records('pages')->find($editing) : null,
            'starters' => $builder->starters(),
            'statusOf' => $status,
            'addressOf' => $addressOf,
        ],

        'actions' => [
            'create' => $actions['create'],
            'delete' => $actions['delete'],
        ],
    ],

    'routes' => function ($router, Insert $insert) use ($show, $actions, $stateFrom, $respond, $builder) {
        // The editor on the page: what to draw, and doing things.
        $router->get('/page-builder/edit/{id}', function (string $id) use ($insert, $respond, $stateFrom, $builder) {
            abort_unless($insert->canOpen(request()->user()), 404);
            $page = $insert->records('pages')->find((int) $id);
            abort_unless($page !== null, 404);
            $state = $stateFrom((array) request()->query());
            $state['section'] ??= $builder->clean($page->field('draft'))[0]['id'] ?? null;

            return $respond($insert, $page, $state);
        })->whereNumber('id')->name('edit');

        $router->post('/page-builder/edit/{id}', function (string $id) use ($insert, $actions, $respond, $stateFrom) {
            abort_unless($insert->canOpen(request()->user()), 403);
            $page = $insert->records('pages')->find((int) $id);
            abort_unless($page !== null, 404);
            $action = (string) request()->input('action');
            abort_unless(isset($actions[$action]) && ! in_array($action, ['create'], true), 404);

            $state = $stateFrom((array) request()->input('state', []));
            $payload = request()->input('payload');
            try {
                $result = $actions[$action]($insert, $state, $payload, $page->id);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $errors = collect($e->errors())->mapWithKeys(fn ($m, $k) => [str_replace('form.', '', $k) => $m[0] ?? ''])->all();

                return $respond($insert, $page, $state, null, true, $errors)->setStatusCode(422);
            } catch (\Throwable $e) {
                report($e);
                app(\App\Inserts\InsertManager::class)->noteError($insert->key, $e);

                return $respond($insert, $page, $state, 'That didn’t work. The problem is noted on the Inserts shelf.', true)->setStatusCode(500);
            }

            if ($action === 'delete' && $result !== null) {
                // Deleted: back to the list of pages in the book.
                return response()->json(['gone' => route('admin.insert', $insert->key), 'stamp' => 'Page deleted.']);
            }
            $stamp = is_string($result) ? $result : (is_array($result) ? ($result['stamp'] ?? null) : null);
            if (is_array($result) && is_array($result['form'] ?? null)) {
                $state = $stateFrom($result['form']);
            }
            $extra = is_array($result) && ! empty($result['moved']) ? ['moved' => url($result['moved'])] : [];

            return $respond($insert, $page, $state, $stamp, (bool) request()->boolean('quiet'), [], $extra);
        })->whereNumber('id')->name('act');

        // The draft as a phone shows it (the editor's Phone view).
        $router->get('/page-builder/preview/{id}', function (string $id) use ($insert, $show) {
            abort_unless($insert->canOpen(request()->user()), 404);
            $page = $insert->records('pages')->find((int) $id);
            abort_unless($page !== null, 404);

            return response($show($insert, $page, true, false))->header('Cache-Control', 'no-store, private');
        })->whereNumber('id')->name('preview');

        // Each page at its own address: what's published for readers, the draft for people who can edit it.
        $pages = rescue(fn () => InsertRecord::query()->where('insert', $insert->key)->where('kind', 'pages')->whereNotNull('slug')->get(['id', 'slug']), collect(), false);
        foreach ($pages as $p) {
            $router->get('/'.$p->slug, function () use ($insert, $show, $p) {
                $page = $insert->records('pages')->find($p->id);
                abort_unless($page !== null, 404);
                if ($page->slug !== $p->slug) {
                    // Moved since this address was set up: to where it is now.
                    return redirect('/'.$page->slug, 301);
                }
                $editor = $insert->canOpen(request()->user());
                if ($page->field('published') === null) {
                    abort_unless($editor, 404);

                    return response($show($insert, $page, true))->header('Cache-Control', 'no-store, private');
                }
                if ($page->field('home') && version_compare((string) config('booklet.version', '1.0'), '1.2', '>=')) {
                    return request()->boolean('edit') ? redirect('/?edit=1') : redirect('/', 301);
                }

                return $show($insert, $page);
            })->name('page.'.$p->id);
        }
    },

    // The home page, when a page is chosen for it (Booklet 1.2; older ones ignore this).
    'home' => function (Insert $insert) use ($show) {
        $page = $insert->records('pages')->all()->first(fn ($p) => $p->field('home') && $p->field('published') !== null);

        return $page ? $show($insert, $page) : null;
    },

    // For the sitemap and plain files (Booklet 1.2).
    'pages' => fn (Insert $insert) => $insert->records('pages')->all()
        ->filter(fn ($p) => $p->field('published') !== null && ! $p->field('home') && $p->slug)
        ->map(fn ($p) => ['path' => '/'.$p->slug, 'updated' => $p->field('published_at')])->values()->all(),

    'slips' => [
        'builder' => function (string $name, Insert $insert) use ($builder) {
            $page = $insert->records('pages')->findBySlug(Builder::address($name));
            if (! $page || $page->field('published') === null) {
                return '';
            }

            return $insert->view('sections', [
                'sections' => $builder->clean($page->field('published')),
                'types' => $builder->types(),
                'live' => false,
                'embedded' => true,
            ]);
        },
    ],
    'slip_help' => ['builder' => '[[builder: page-address]]'],

    'site' => ['css' => ['builder.css']],

    // What Stacy can tell about built pages.
    'stacy' => [
        'built_pages' => [
            'about' => 'List the pages made with the page builder: name, address, whether they are on the site (or have changes waiting), which is the home page, and their sections.',
            'label' => 'Tells you which pages were built, and what’s on them',
            'look' => function (array $args, Insert $insert) use ($builder, $status, $addressOf) {
                $pages = $insert->records('pages')->all();

                return [
                    'pages' => $pages->map(fn ($p) => [
                        'name' => $p->field('title'),
                        'address' => $addressOf($p),
                        'status' => $status($p)[1],
                        'sections' => array_map(fn ($s) => ($builder->type($s['type'])['name'] ?? $s['type']).($builder->summary($s) !== '' ? ': '.$builder->summary($s) : ''), $builder->clean($p->field('draft'))),
                        'show_in_a_post_with' => '[[builder: '.$p->slug.']]',
                    ])->values()->all(),
                    'links' => $pages->take(5)->map(fn ($p) => ['label' => $p->field('title'), 'meta' => $status($p)[1], 'href' => url('/'.$p->slug).'?edit=1'])->values()->all(),
                ];
            },
        ],
    ],
];
