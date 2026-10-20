<?php

/*
 * Gallery: photo albums made from pictures in Media, shown in any post or
 * page with [[gallery: album-name]], and opened large in a lightbox.
 */

use App\Booklet\Facades\Booklet;
use App\Inserts\Insert;

/** The pictures in Media, as [path => ['url', 'thumb', 'alt']]. */
$pictures = function (): array {
    $out = [];
    foreach (Booklet::assets()->inContainer('media')->filter(fn ($a) => $a->isImage()) as $asset) {
        $path = $asset->path();
        $out[$path] = [
            'url' => \App\Support\Disk::of('public')->url($path),
            'thumb' => \App\Support\Pictures::srcset($path) !== '' ? \App\Support\Pictures::url($path, 320) : \App\Support\Disk::of('public')->url($path),
            'alt' => (string) $asset->get('alt', ''),
        ];
    }

    return $out;
};

return [
    'name' => 'Gallery',
    'about' => 'Photo albums made from pictures in Media, shown in any post or page and opened large in a lightbox.',
    'version' => '1.0',
    'author' => 'Booklet',
    'sticker' => 'sky',
    'needs' => '1.0',

    'settings' => [
        'columns' => ['type' => 'select', 'label' => 'Pictures across', 'default' => '3', 'options' => ['2' => 'Two', '3' => 'Three', '4' => 'Four']],
        'captions' => ['type' => 'toggle', 'label' => 'Show captions', 'hint' => 'Each picture’s description, under it', 'default' => true],
        'lightbox' => ['type' => 'toggle', 'label' => 'Open pictures large', 'hint' => 'Readers can step through the album with arrow keys', 'default' => true],
    ],

    'page' => [
        'title' => 'Albums',
        'chapter' => 'Media',
        'left' => 'admin-left',
        'form' => ['title' => '', 'about' => ''],

        'data' => function (Insert $insert, array $form, $editing) use ($pictures) {
            $albums = $insert->records('albums');

            return [
                'albums' => $albums->all(),
                'album' => $albums->find($editing),
                'pictures' => $pictures(),
            ];
        },

        'actions' => [
            'create' => function (Insert $insert, array $form) {
                $data = $insert->validate($form, ['title' => 'required|string|max:120'], [], ['title' => 'album name']);
                $album = $insert->records('albums')->create(['title' => trim($data['title']), 'about' => '', 'pictures' => []]);

                return ['editing' => $album->id, 'form' => ['title' => $album->field('title'), 'about' => ''], 'stamp' => 'Album made. Pick its pictures.'];
            },
            'open' => function (Insert $insert, array $form, $id) {
                $album = $insert->records('albums')->find((int) $id);

                return $album ? ['editing' => $album->id, 'form' => ['title' => $album->field('title'), 'about' => (string) $album->field('about')]] : null;
            },
            'save' => function (Insert $insert, array $form, $payload, $editing) {
                $album = $insert->records('albums')->find($editing);
                if (! $album) {
                    return null;
                }
                $data = $insert->validate($form, ['title' => 'required|string|max:120', 'about' => 'nullable|string|max:600'], [], ['title' => 'album name']);
                $insert->records('albums')->update($album, ['title' => trim($data['title']), 'about' => trim((string) ($data['about'] ?? ''))]);

                return 'Saved.';
            },
            'picture' => function (Insert $insert, array $form, $path, $editing) use ($pictures) {
                $album = $insert->records('albums')->find($editing);
                if (! $album || ! array_key_exists((string) $path, $pictures())) {
                    return null;
                }
                $chosen = (array) $album->field('pictures', []);
                $chosen = in_array($path, $chosen, true) ? array_values(array_diff($chosen, [$path])) : [...$chosen, (string) $path];
                $insert->records('albums')->update($album, ['pictures' => $chosen]);

                return null;
            },
            'move' => function (Insert $insert, array $form, $payload, $editing) {
                $album = $insert->records('albums')->find($editing);
                [$path, $step] = [(string) ($payload['path'] ?? ''), (int) ($payload['step'] ?? 0)];
                $chosen = (array) $album?->field('pictures', []);
                $at = array_search($path, $chosen, true);
                if (! $album || $at === false || ! isset($chosen[$at + $step])) {
                    return null;
                }
                [$chosen[$at], $chosen[$at + $step]] = [$chosen[$at + $step], $chosen[$at]];
                $insert->records('albums')->update($album, ['pictures' => $chosen]);

                return null;
            },
            'delete' => function (Insert $insert, array $form, $payload, $editing) {
                $insert->records('albums')->delete((int) $editing);

                return ['editing' => null, 'form' => ['title' => '', 'about' => ''], 'stamp' => 'Album deleted. Its pictures are still in Media.'];
            },
            'close' => fn () => ['editing' => null, 'form' => ['title' => '', 'about' => '']],
        ],
    ],

    'slips' => [
        'gallery' => function (string $name, Insert $insert) use ($pictures) {
            $album = $insert->records('albums')->findBySlug(\Illuminate\Support\Str::slug($name));
            if (! $album) {
                return '';
            }
            $all = $pictures();
            $shown = collect((array) $album->field('pictures', []))->filter(fn ($p) => isset($all[$p]))
                ->map(fn ($p) => ['path' => $p, 'srcset' => \App\Support\Pictures::srcset($p)] + $all[$p])->values();

            return $shown->isEmpty() ? '' : $insert->view('album', ['album' => $album, 'pictures' => $shown]);
        },
    ],
    'slip_help' => ['gallery' => '[[gallery: album-name]]'],

    // A cut-out for blocks fields: pick an album from a list instead of typing its name.
    'blocks' => [
        'album' => [
            'name' => 'Album',
            'about' => 'One of your albums, as a gallery.',
            'tone' => 'sky',
            'fields' => [
                'album' => ['type' => 'select', 'display' => 'Album',
                    // Worked out when the editor opens, so new albums are there.
                    'options' => fn (Insert $insert) => ['' => 'Choose an album…'] + $insert->records('albums')->all()
                        ->mapWithKeys(fn ($a) => [(string) $a->slug => (string) $a->field('title')])->all()],
            ],
            'show' => function (array $block, Insert $insert) use ($pictures) {
                $album = $block['album'] ? $insert->records('albums')->findBySlug((string) $block['album']) : null;
                if (! $album) {
                    return '';
                }
                $all = $pictures();
                $shown = collect((array) $album->field('pictures', []))->filter(fn ($p) => isset($all[$p]))
                    ->map(fn ($p) => ['path' => $p, 'srcset' => \App\Support\Pictures::srcset($p)] + $all[$p])->values();

                return $shown->isEmpty() ? '' : $insert->view('album', ['album' => $album, 'pictures' => $shown]);
            },
        ],
    ],

    'site' => ['css' => ['gallery.css'], 'js' => ['gallery.js']],

    // On the Contents page: pictures added this week that aren't in an album yet.
    'contents' => [
        'new-pictures' => [
            'title' => 'New pictures',
            'show' => function (Insert $insert) {
                $inAlbums = $insert->records('albums')->all()->flatMap(fn ($a) => (array) $a->field('pictures', []))->flip();
                $fresh = Booklet::assets()->inContainer('media')
                    ->filter(fn ($a) => $a->isImage() && ! isset($inAlbums[$a->path()]) && rescue(fn () => \App\Support\Disk::of('public')->lastModified($a->path()), 0, false) >= now()->subDays(7)->timestamp)
                    ->count();
                if ($fresh < 3) {
                    return null; // a picture or two is just a post's pictures
                }

                return [
                    'text' => $fresh.' pictures came into Media this week and aren’t in an album yet. Make one, or ask Stacy to.',
                    'link' => ['label' => 'Albums', 'href' => route('admin.insert', $insert->key)],
                ];
            },
        ],
    ],

    // What Stacy can do with albums.
    'stacy' => [
        'albums' => [
            'about' => 'List the photo albums: name, how many pictures, and the [[gallery: …]] line that shows one in a post or page.',
            'label' => 'Tells you which albums there are, and how to show one',
            'look' => function (array $args, Insert $insert) {
                $albums = $insert->records('albums')->all();
                $page = route('admin.insert', $insert->key);

                return [
                    'albums' => $albums->map(fn ($a) => [
                        'name' => $a->field('title'),
                        'pictures' => count((array) $a->field('pictures', [])),
                        'show_with' => '[[gallery: '.$a->slug.']]',
                    ])->values()->all(),
                    'links' => $albums->take(5)->map(fn ($a) => ['label' => $a->field('title'), 'meta' => count((array) $a->field('pictures', [])).' pictures', 'href' => $page.'?open='.$a->id])->values()->all(),
                ];
            },
        ],
        'make_album' => [
            'about' => 'Propose making a photo album from pictures in Media (use find_media first to find their file paths). Give it a name, and optionally a line about it.',
            'label' => 'Makes an album from pictures in Media',
            'takes' => [
                'name' => ['type' => 'string', 'about' => 'The album’s name'],
                'pictures' => ['type' => 'list', 'about' => 'File paths of the pictures, as find_media returns them'],
                'about' => ['type' => 'string', 'about' => 'Optional line about the album'],
            ],
            'needs' => ['name', 'pictures'],
            'propose' => function (array $args, Insert $insert) use ($pictures) {
                $all = $pictures();
                $chosen = array_values(array_unique(array_filter((array) $args['pictures'], fn ($p) => is_string($p) && isset($all[$p]))));
                if ($chosen === []) {
                    return 'None of those are pictures in Media. Use find_media to find their file paths.';
                }
                $name = \Illuminate\Support\Str::limit(trim((string) $args['name']), 120, '');
                if ($insert->records('albums')->findBySlug(\Illuminate\Support\Str::slug($name))) {
                    return 'There’s already an album called “'.$name.'”. Ask for another name.';
                }

                return [
                    'summary' => 'Make the album “'.$name.'” with '.count($chosen).' '.\Illuminate\Support\Str::plural('picture', count($chosen)).'.',
                    'button' => 'Yes, make it',
                    'data' => ['name' => $name, 'about' => trim((string) ($args['about'] ?? '')), 'pictures' => $chosen],
                ];
            },
            'do' => function (array $data, Insert $insert) use ($pictures) {
                $all = $pictures();
                $album = $insert->records('albums')->create([
                    'title' => $data['name'],
                    'about' => $data['about'] ?? '',
                    'pictures' => array_values(array_filter((array) $data['pictures'], fn ($p) => isset($all[$p]))),
                ]);

                return [
                    'text' => 'Done. Put [[gallery: '.$album->slug.']] in a post or page to show it.',
                    'link' => ['label' => $album->field('title'), 'meta' => 'Album', 'href' => route('admin.insert', $insert->key).'?open='.$album->id],
                ];
            },
        ],
    ],

    // Pictures that leave Media leave the albums too.
    'every' => [
        'daily' => function (Insert $insert) use ($pictures) {
            $there = $pictures();
            foreach ($insert->records('albums')->all() as $album) {
                $kept = array_values(array_filter((array) $album->field('pictures', []), fn ($p) => isset($there[$p])));
                if ($kept !== (array) $album->field('pictures', [])) {
                    $insert->records('albums')->update($album, ['pictures' => $kept]);
                }
            }
        },
    ],
];
