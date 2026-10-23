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
    // Read fresh (not this request's copy): a page may have just been taken off the site.
    $entry = rescue(fn () => Booklet::entries()->all()->first(fn ($e) => $e->status() === 'published' && trim((string) $e->uri(), '/') === $address), null, false)
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

/*
 * Building from one of the site's own pages (or posts): its title, words,
 * headings, pictures and cut-outs, as sections to start from, in order.
 * Words become plain text (a heading starts a new section; lists become a
 * line each). Cut-outs become their nearest section: a quote → Quotes,
 * pictures → Pictures, two columns → Columns, a call to action → Call to
 * action, a video → Video, a note → words with a label. Pictures that are
 * in Media come along; others are left behind.
 */
$fromEntry = function (\App\Booklet\Data\Entry $entry) use ($builder, $isPicture): array {
    $mediaPath = function (string $src) use ($isPicture): ?string {
        $base = rtrim(\App\Support\Disk::of('public')->url(''), '/').'/';
        $path = parse_url($src, PHP_URL_PATH) ?: $src;
        foreach ([$base, (string) parse_url($base, PHP_URL_PATH)] as $prefix) {
            if ($prefix === '' || $prefix === '/') {
                continue;
            }
            if (str_starts_with($src, $prefix)) {
                $path = substr($src, strlen($prefix));
                break;
            }
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }
        $path = rawurldecode(ltrim($path, '/'));

        return $path !== '' && $isPicture($path) ? $path : null;
    };
    $media = fn ($p) => is_string($p) && $p !== '' && $isPicture($p) ? $p : null;
    $flat = fn (string $text): string => trim((string) preg_replace('/\s+/u', ' ', $text));

    $sections = [];
    // Closer together than new sections: it reads like the page it came from.
    $add = function (array $section) use (&$sections) {
        if (count($sections) < Builder::MAX_SECTIONS) {
            $section['look']['space'] = $section['type'] === 'hero' ? 'normal' : 'snug';
            $sections[] = $section;
        }
    };

    // The opening: its title, its line about it, its cover picture.
    $hero = $builder->make('hero');
    $cover = $media(((array) $entry->get('cover', []))[0] ?? null);
    $hero['fields'] = array_merge($hero['fields'], [
        'eyebrow' => '',
        'title' => $builder->line($entry->title(), 160),
        'lede' => $builder->words((string) ($entry->get('excerpt') ?? $entry->get('meta_description') ?? ''), 600),
        'button' => ['label' => '', 'url' => ''],
        'picture' => (string) $cover,
        'layout' => $cover ? 'side' : 'none',
    ]);
    $add($hero);

    // Words waiting to become a "Words and a picture" section.
    // The words waiting to become a section, and which side its picture goes.
    $w = new class
    {
        /** @var array{title: string, eyebrow: string, body: list<string>, picture: string}|null */
        public ?array $part = null;

        public string $side = 'right';
    };
    $flush = function () use ($w, $builder, $add) {
        if ($w->part === null || ($w->part['title'] === '' && $w->part['body'] === [] && $w->part['picture'] === '')) {
            $w->part = null;

            return;
        }
        $section = $builder->make('text-picture');
        $section['fields'] = array_merge($section['fields'], [
            'eyebrow' => $w->part['eyebrow'],
            'title' => $builder->line($w->part['title'], 160),
            'body' => $builder->words(implode("\n\n", $w->part['body']), 6000),
            'button' => ['label' => '', 'url' => ''],
            'picture' => $w->part['picture'],
            'side' => $w->side,
        ]);
        if ($w->part['picture'] !== '') {
            $w->side = $w->side === 'right' ? 'left' : 'right';
        }
        $add($section);
        $w->part = null;
    };
    $start = function (string $title = '', string $eyebrow = '') use ($w, $flush) {
        $flush();
        $w->part = ['title' => $title, 'eyebrow' => $eyebrow, 'body' => [], 'picture' => ''];
    };

    // Markdown, a block at a time (raw HTML left out).
    $words = function (string $markdown) use ($w, $start, $mediaPath, $flat) {
        $markdown = \App\Inserts\InsertManager::stripSlips($markdown);
        if (trim($markdown) === '') {
            return;
        }
        $html = (string) \Illuminate\Support\Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="pb-root">'.$html.'</div>', LIBXML_NONET);
        libxml_clear_errors();
        $root = $doc->getElementById('pb-root');
        foreach ($root ? $root->childNodes : [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['h1', 'h2', 'h3'], true)) {
                $start($flat($node->textContent));

                continue;
            }
            $w->part ??= ['title' => '', 'eyebrow' => '', 'body' => [], 'picture' => ''];
            foreach ($node->getElementsByTagName('img') as $img) {
                if ($w->part['picture'] === '' && ($path = $mediaPath((string) $img->getAttribute('src')))) {
                    $w->part['picture'] = $path;
                }
            }
            $text = $flat($node->textContent);
            if (in_array($tag, ['ul', 'ol'], true)) {
                $lines = [];
                foreach ($node->getElementsByTagName('li') as $li) {
                    $lines[] = '• '.$flat($li->textContent);
                }
                $text = implode("\n", $lines);
            }
            if ($text !== '') {
                $w->part['body'][] = $text;
            }
        }
    };
    $plain = fn (string $markdown): string => trim(strip_tags((string) \Illuminate\Support\Str::markdown(
        \App\Inserts\InsertManager::stripSlips($markdown), ['html_input' => 'strip'])));

    $words((string) $entry->get('content', ''));

    // Its cut-outs (and tables), in the order its blueprint has them.
    foreach (\App\Support\Blocks::blueprintFields($entry) as $handle => $field) {
        $value = $entry->get($handle);
        if (($field['type'] ?? null) === 'grid' && is_array($value) && $value !== []) {
            $w->part ??= ['title' => '', 'eyebrow' => '', 'body' => [], 'picture' => ''];
            $w->part['body'][] = \App\Support\Rows::text($value, \App\Support\Rows::columns($field));

            continue;
        }
        if (($field['type'] ?? null) !== 'blocks' || ! is_array($value)) {
            continue;
        }
        foreach ($value as $block) {
            if (! is_array($block) || ! ($block['enabled'] ?? true)) {
                continue;
            }
            switch ($block['type'] ?? '') {
                case 'text':
                    $words((string) ($block['text'] ?? ''));
                    break;

                case 'quote':
                    $flush();
                    $q = $builder->make('quotes');
                    $q['fields'] = ['title' => '', 'items' => [['words' => $builder->words((string) ($block['quote'] ?? ''), 2000),
                        'name' => $builder->line((string) ($block['cite'] ?? '')), 'role' => '', 'picture' => '']]];
                    $q['look']['background'] = 'none';
                    $add($q);
                    break;

                case 'picture':
                    $pic = $media(((array) ($block['picture'] ?? []))[0] ?? ($block['picture'] ?? null));
                    if (! $pic) {
                        break;
                    }
                    if ($w->part !== null && $w->part['picture'] === '') {
                        $w->part['picture'] = $pic; // beside the words before it
                    } else {
                        $start();
                        $w->part['picture'] = $pic;
                        if (trim((string) ($block['caption'] ?? '')) !== '') {
                            $w->part['body'][] = $flat((string) $block['caption']);
                        }
                    }
                    break;

                case 'gallery':
                    $pics = array_values(array_filter(array_map($media, (array) ($block['pictures'] ?? []))));
                    if ($pics !== []) {
                        $flush();
                        $g = $builder->make('gallery');
                        $g['fields'] = ['title' => $builder->line((string) ($block['caption'] ?? '')), 'pictures' => array_slice($pics, 0, 40),
                            'across' => count($pics) % 3 === 0 || count($pics) > 4 ? '3' : '2', 'captions' => false];
                        $add($g);
                    }
                    break;

                case 'button':
                    $flush();
                    $c = $builder->make('cta');
                    $url = rescue(fn () => $builder->url((string) ($block['link'] ?? '')), '', false);
                    $c['fields'] = ['title' => $builder->line((string) ($block['heading'] ?? ''), 160), 'words' => $builder->words((string) ($block['text'] ?? '')),
                        'button' => ['label' => $builder->line((string) ($block['label'] ?? ''), 60), 'url' => $url], 'button2' => ['label' => '', 'url' => '']];
                    $add($c);
                    break;

                case 'columns':
                    $flush();
                    $col = $builder->make('columns');
                    $item = function (string $markdown) use ($builder, $plain): array {
                        $title = preg_match('/^\s*#{1,6}\s*(.+)$/m', $markdown, $m) ? trim($m[1]) : '';
                        $rest = $title !== '' ? (string) preg_replace('/^\s*#{1,6}\s*.+$/m', '', $markdown, 1) : $markdown;

                        return ['mark' => '', 'title' => $builder->line($title), 'words' => $builder->words($plain($rest))];
                    };
                    $col['fields'] = ['title' => '', 'intro' => '', 'across' => '2',
                        'items' => [$item((string) ($block['left'] ?? '')), $item((string) ($block['right'] ?? ''))]];
                    $add($col);
                    break;

                case 'video':
                    if (Builder::embed(trim((string) ($block['url'] ?? '')))) {
                        $flush();
                        $v = $builder->make('video');
                        $v['fields'] = ['title' => '', 'url' => trim((string) $block['url']), 'caption' => $builder->line((string) ($block['caption'] ?? ''), 300)];
                        $add($v);
                    }
                    break;

                case 'note':
                    $flush();
                    $label = ['tip' => 'Tip', 'info' => 'Good to know', 'warning' => 'Careful'][$block['kind'] ?? 'tip'] ?? 'Tip';
                    $w->part = ['title' => '', 'eyebrow' => $label, 'body' => [$plain((string) ($block['text'] ?? ''))], 'picture' => ''];
                    $flush();
                    $sections[count($sections) - 1]['look']['background'] = 'soft'; // a box, as it was
                    break;

                case 'featured':
                case 'break':
                    break;

                default:
                    // An insert's own cut-out: its words, at least.
                    $text = \App\Support\Blocks::text([$block]);
                    if (trim($text) !== '') {
                        $w->part ??= ['title' => '', 'eyebrow' => '', 'body' => [], 'picture' => ''];
                        $w->part['body'][] = $text;
                    }
            }
        }
    }
    $flush();

    // A short opening line with no heading of its own: under the title, where it stood.
    $first = $sections[1] ?? null;
    if ($first && $first['type'] === 'text-picture' && $sections[0]['fields']['lede'] === '' && $first['fields']['title'] === ''
        && $first['fields']['picture'] === '' && $first['fields']['eyebrow'] === '' && ! str_contains($first['fields']['body'], "\n") && mb_strlen($first['fields']['body']) <= 300) {
        $sections[0]['fields']['lede'] = $first['fields']['body'];
        array_splice($sections, 1, 1);
    }

    return $sections;
};

/** Pages and posts a built page can start from (and take the place of). */
$entriesToBuildFrom = function (): array {
    $out = [];
    foreach (rescue(fn () => app(\App\Booklet\Site::class)->published(), collect(), false) as $e) {
        if (! $e->id() || ! $e->uri()) {
            continue;
        }
        $group = $e->collection()?->dated() ? 'Posts' : 'Pages';
        $out[$group][$e->id()] = $e->title().' ('.$e->uri().')';
    }
    ksort($out);
    if (isset($out['Pages'])) {
        $out = ['Pages' => $out['Pages']] + $out; // pages first
    }

    return $out;
};

/** Is this entry the site's home page (Settings › home page)? */
$isHomeEntry = fn (string $id): bool => (string) app(\App\Booklet\Site::class)->setting('homepage', 'posts') === $id;

/** Point menu links at an entry to an address instead (or back). */
$repointMenus = function (string $entryId, string $address, bool $back = false): array {
    $changed = [];
    foreach (array_keys(rescue(fn () => \App\Booklet\Support\Menus::allWithSeasons(), [], false)) as $handle) {
        $tree = Booklet::trees()->navigation($handle);
        if (! $tree) {
            continue;
        }
        $touched = false;
        $walk = function (array $branches) use (&$walk, &$touched, $entryId, $address, $back): array {
            foreach ($branches as $i => $branch) {
                if (! $back && ($branch['entry'] ?? null) === $entryId) {
                    $own = isset($branch['title']); // a name of its own, or the page's
                    $title = $branch['title'] ?? Booklet::entries()->find($entryId)?->title();
                    unset($branch['entry'], $branch['follow']);
                    $branch = ['url' => $address, 'title' => $title, 'pb_was' => $entryId] + ($own ? [] : ['pb_named' => true]) + $branch;
                    $touched = true;
                } elseif ($back && (($branch['pb_was'] ?? null) === $entryId || (! isset($branch['entry']) && ($branch['url'] ?? null) === $address))) {
                    // (By its address too, if the menu was saved again since and forgot the note.)
                    if (! empty($branch['pb_named'])) {
                        unset($branch['title']); // named after the page again
                    }
                    unset($branch['url'], $branch['pb_was'], $branch['pb_named']);
                    $branch = ['entry' => $entryId] + $branch;
                    $touched = true;
                }
                if (! empty($branch['children'])) {
                    $branch['children'] = $walk((array) $branch['children']);
                }
                $branches[$i] = $branch;
            }

            return $branches;
        };
        $new = $walk($tree->tree());
        if ($touched) {
            $tree->setTree($new)->save();
            $changed[] = $handle;
        }
    }

    return $changed;
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

/**
 * A built page takes the place of the page it was built from: the old one
 * is kept as a draft (nothing is deleted), its address and menu links go to
 * the built page. The home page instead: the built page becomes the home
 * page (Booklet 1.2), and the old one is left as it is.
 */
$takeOver = function (Insert $insert, InsertRecord $page) use ($taken, $repointMenus, $homeWorks): ?string {
    $replaces = (array) $page->field('replaces', []);
    $entry = Booklet::entries()->find((string) ($replaces['entry'] ?? ''));
    if (! $entry) {
        $insert->records('pages')->update($page, ['replaces' => null]);

        return null;
    }
    if (! empty($replaces['home'])) {
        if (! $homeWorks()) {
            return null;
        }
        foreach ($insert->records('pages')->all() as $p) {
            if ($p->field('home') && $p->id !== $page->id) {
                $insert->records('pages')->update($p, ['home' => false]);
            }
        }
        $insert->records('pages')->update($page, ['home' => true, 'replaces' => $replaces + ['done' => now()->toIso8601String(), 'menus' => []]]);

        return 'It’s your home page now. “'.$replaces['title'].'” is kept as it was (Page tab › Put the old page back).';
    }

    $address = (string) $replaces['address'];
    $user = request()->user();
    if ($entry->published()) {
        $entry->setAttribute('published', false)->save();
        \App\Models\ActivityLog::record('unpublished', 'publishing', 'entry', $entry->id(), $entry->title());
        rescue(fn () => Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'unpublished', (string) $user?->id, 'Replaced by a built page'), null, false);
    }
    if ($why = $taken($address, $page->id)) {
        return 'Published at /'.$page->slug.'. It couldn’t take /'.$address.': '.$why;
    }
    $draftAddress = (string) $page->slug;
    $page->forceFill(['slug' => $address])->save();
    // Nothing forwards away from its new address; its draft address forwards to it.
    rescue(fn () => \App\Support\Redirects::moved('/'.$draftAddress, '/'.$address), null, false);
    $menus = $repointMenus((string) $entry->id(), '/'.$address);
    $insert->records('pages')->update($page, ['replaces' => $replaces + ['done' => now()->toIso8601String(), 'menus' => $menus]]);

    return 'It’s on the site at /'.$address.', in place of “'.$replaces['title'].'” (kept as a draft'.($menus !== [] ? '; menu links point here now' : '').').';
};

/** Undo that: the old page back at its address and in its menus; the built page back to a draft. */
$giveBack = function (Insert $insert, InsertRecord $page) use ($taken, $repointMenus): string {
    $replaces = (array) $page->field('replaces', []);
    $entry = Booklet::entries()->find((string) ($replaces['entry'] ?? ''));
    if (! empty($replaces['home'])) {
        $insert->records('pages')->update($page, ['home' => false, 'replaces' => array_diff_key($replaces, ['done' => 1, 'menus' => 1])]);

        return '“'.($replaces['title'] ?? 'Your old home page').'” is the home page again. This one is still published at /'.$page->slug.'.';
    }
    // The built page steps aside first, to a free address of its own.
    $base = (string) $page->slug.'-built';
    for ($n = 2, $try = $base; $taken($try, $page->id) !== null && $n < 50; $n++) {
        $try = $base.'-'.$n;
    }
    $page->forceFill(['slug' => $try])->save();
    $insert->records('pages')->update($page, ['published' => null, 'home' => false, 'replaces' => array_diff_key($replaces, ['done' => 1, 'menus' => 1])]);
    if ($entry) {
        if (! $entry->published()) {
            $entry->setAttribute('published', true)->save();
            \App\Models\ActivityLog::record('published', 'publishing', 'entry', $entry->id(), $entry->title());
        }
        $repointMenus((string) $entry->id(), '/'.$replaces['address'], true);
    }

    return '“'.($replaces['title'] ?? 'The old page').'” is back at /'.$replaces['address'].'. This built page is a draft again, at /'.$try.'.';
};

/*
 * What the editor can do. Each one is given the editor's state (which
 * section is chosen, which tab is open…) as $form, what was pressed as
 * $payload, and the page's id; it answers with words to show, or
 * ['form' => the new state, 'stamp' => words].
 */
$actions = [
    // Pages ----------------------------------------------------------------------------
    'create' => function (Insert $insert, array $form) use ($builder, $taken, $fromEntry, $isHomeEntry, $homeWorks) {
        $entry = null;
        if (($form['starter'] ?? '') === 'entry') {
            $entry = is_string($form['from'] ?? null) && $form['from'] !== '' ? Booklet::entries()->find($form['from']) : null;
            if (! $entry || ! $entry->published() || ! $entry->uri()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['form.from' => 'Choose one of your pages or posts to build from.']);
            }
            if ($isHomeEntry((string) $entry->id()) && ! $homeWorks()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['form.from' => 'Taking the place of your home page needs Booklet 1.2. Choose another page, or start from a layout.']);
            }
            $form['title'] = trim((string) ($form['title'] ?? '')) !== '' ? $form['title'] : $entry->title();
        }
        $data = $insert->validate($form, ['title' => 'required|string|max:120', 'starter' => 'nullable|string'], [], ['title' => 'page name']);
        $address = Builder::address($entry ? trim((string) $entry->uri(), '/').'-new' : $data['title']);
        // offer, offer-2…: a free address to start with (it can be changed under Page).
        for ($n = 2, $try = $address; $taken($try) !== null && $n < 50; $n++) {
            $try = $address.'-'.$n;
        }
        if ($why = $taken($try)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['form.title' => $why]);
        }
        if ($entry) {
            $sections = $fromEntry($entry);
        } else {
            $sections = $builder->starter((string) ($data['starter'] ?? 'blank'));
            if ($sections !== [] && $sections[0]['type'] === 'hero') {
                $sections[0]['fields']['title'] = trim($data['title']);
            }
        }
        $page = $insert->records('pages')->create([
            'title' => trim($data['title']),
            'description' => $entry ? trim((string) ($entry->get('meta_description') ?? $entry->get('excerpt') ?? '')) : '',
            'draft' => $sections, 'published' => null, 'home' => false, 'history' => [], 'edited_at' => now()->toIso8601String(),
            // Taking the place of one of the site's pages, when published.
            'replaces' => $entry ? ['entry' => (string) $entry->id(), 'title' => $entry->title(), 'address' => trim((string) $entry->uri(), '/'), 'home' => $isHomeEntry((string) $entry->id())] : null,
        ], $try);

        return ['editing' => $page->id, 'form' => ['title' => '', 'starter' => 'landing', 'from' => ''], 'stamp' => 'Made. Opening it…'];
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
    'publish' => function (Insert $insert, array $form, $payload, $editing) use ($builder, $addressOf, $takeOver) {
        $page = $insert->records('pages')->find($editing);
        if (! $page) {
            return null;
        }
        $first = $page->field('published') === null;
        $insert->records('pages')->update($page, ['published' => $builder->clean($page->field('draft')), 'published_at' => now()->toIso8601String()]);
        \App\Models\ActivityLog::record($first ? 'published the built page' : 'updated the built page', 'publishing', 'page-builder', (string) $page->id, (string) $page->field('title'));

        // Built from one of the site's pages: it takes that page's place now.
        $replaces = (array) $page->field('replaces', []);
        if ($replaces !== [] && empty($replaces['done'])) {
            $words = $takeOver($insert, $page->fresh() ?? $page);
            if ($words !== null) {
                return ['stamp' => $words, 'moved' => $addressOf($page->fresh() ?? $page)];
            }
        }

        return $first ? 'It’s on the site at '.$addressOf($page).'.' : 'Published. Readers see the new version now.';
    },
    'restore' => function (Insert $insert, array $form, $payload, $editing) use ($giveBack, $addressOf) {
        $page = $insert->records('pages')->find($editing);
        if (! $page || empty($page->field('replaces')['done'])) {
            return null;
        }
        $words = $giveBack($insert, $page);

        return ['stamp' => $words, 'moved' => $addressOf($page->fresh() ?? $page)];
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
        'form' => ['title' => '', 'starter' => 'landing', 'from' => ''],

        'data' => fn (Insert $insert, array $form, $editing) => [
            'pages' => $insert->records('pages')->all(),
            'made' => $editing ? $insert->records('pages')->find($editing) : null,
            'starters' => $builder->starters(),
            'entries' => $entriesToBuildFrom(),
            'homeId' => (string) app(\App\Booklet\Site::class)->setting('homepage', 'posts'),
            'homeWorks' => $homeWorks(),
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
                    // Not its address any more (moved, or handed back to the page it replaced):
                    // whatever the site has there now, as if this page had never been here.
                    return app()->call([app(\App\Http\Controllers\SiteController::class), 'show']);
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
