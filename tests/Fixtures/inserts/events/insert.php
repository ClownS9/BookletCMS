<?php

/*
 * Events: things happening (a market stall, a class, a launch) with a date,
 * a time and a place. A page at /events, "add to my calendar" files, and
 * [[events]] to show what's coming in any post or page.
 */

use App\Inserts\Insert;
use App\Models\InsertRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** When it starts and ends, in the site's time. */
$when = function (InsertRecord $e): array {
    $tz = config('app.timezone');
    $start = rescue(fn () => Carbon::parse((string) $e->field('starts'), $tz), null, false);
    $end = $e->field('ends') ? rescue(fn () => Carbon::parse((string) $e->field('ends'), $tz), null, false) : null;

    return [$start, $end && $start && $end->gt($start) ? $end : null];
};

/** Upcoming first (soonest at the top), then past (latest first). */
$sorted = function (Insert $insert) use ($when): array {
    $all = $insert->records('events')->all();
    $now = now();
    $upcoming = $all->filter(fn ($e) => ($w = $when($e))[0] && ($w[1] ?? $w[0]->copy()->endOfDay())->gte($now))
        ->sortBy(fn ($e) => $when($e)[0]->timestamp)->values();
    $past = $all->reject(fn ($e) => $upcoming->contains('id', $e->id))
        ->sortByDesc(fn ($e) => $when($e)[0]?->timestamp ?? 0)->values();

    return [$upcoming, $past];
};

/** A calendar file (.ics) any calendar app can add. */
$ics = function (Collection $events, Insert $insert) use ($when): string {
    $esc = fn ($s) => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], (string) $s);
    $site = app(\App\Booklet\Site::class);
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Booklet//Events//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:'.$esc($site->name())];
    foreach ($events as $e) {
        [$start, $end] = $when($e);
        if (! $start) {
            continue;
        }
        $allDay = (bool) $e->field('all_day');
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:event-'.$e->id.'@'.(parse_url(url('/'), PHP_URL_HOST) ?: 'booklet');
        $lines[] = 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z');
        if ($allDay) {
            $lines[] = 'DTSTART;VALUE=DATE:'.$start->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.($end ?? $start)->copy()->addDay()->format('Ymd');
        } else {
            $lines[] = 'DTSTART:'.$start->copy()->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTEND:'.($end ?? $start->copy()->addHours(2))->copy()->utc()->format('Ymd\THis\Z');
        }
        $lines[] = 'SUMMARY:'.$esc($e->field('title'));
        if ($e->field('venue') || $e->field('address')) {
            $lines[] = 'LOCATION:'.$esc(trim($e->field('venue').', '.$e->field('address'), ', '));
        }
        if ($e->field('about')) {
            $lines[] = 'DESCRIPTION:'.$esc($e->field('about'));
        }
        $lines[] = 'URL:'.$site->absolute(route('site.insert.events.index').'#'.$e->slug);
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';

    // Lines longer than 75 bytes are folded, as the calendar format asks.
    return implode("\r\n", array_map(fn ($l) => rtrim(chunk_split($l, 74, "\r\n "), "\r\n "), $lines))."\r\n";
};

$rules = [
    'title' => 'required|string|max:160',
    'starts_date' => 'required|date_format:Y-m-d',
    'starts_time' => 'nullable|date_format:H:i',
    'ends_date' => 'nullable|date_format:Y-m-d|after_or_equal:starts_date',
    'ends_time' => 'nullable|date_format:H:i',
    'all_day' => 'boolean',
    'venue' => 'nullable|string|max:160',
    'address' => 'nullable|string|max:300',
    'about' => 'nullable|string|max:2000',
    'link' => 'nullable|url|max:500',
];
$names = ['starts_date' => 'date', 'starts_time' => 'start time', 'ends_date' => 'end date', 'ends_time' => 'end time', 'link' => 'link'];
$blank = ['title' => '', 'starts_date' => '', 'starts_time' => '', 'ends_date' => '', 'ends_time' => '', 'all_day' => false, 'venue' => '', 'address' => '', 'about' => '', 'link' => ''];

/** Form values into what's kept (and back). */
$toRecord = function (array $d): array {
    $allDay = (bool) ($d['all_day'] ?? false);
    $starts = $d['starts_date'].' '.($allDay || empty($d['starts_time']) ? '00:00' : $d['starts_time']);
    $ends = ! empty($d['ends_date']) || ! empty($d['ends_time'])
        ? ($d['ends_date'] ?: $d['starts_date']).' '.($allDay || empty($d['ends_time']) ? '23:59' : $d['ends_time'])
        : null;

    return ['title' => trim($d['title']), 'starts' => $starts, 'ends' => $ends, 'all_day' => $allDay,
        'venue' => trim((string) ($d['venue'] ?? '')), 'address' => trim((string) ($d['address'] ?? '')),
        'about' => trim((string) ($d['about'] ?? '')), 'link' => trim((string) ($d['link'] ?? ''))];
};
$toForm = function (InsertRecord $e) use ($blank): array {
    [$sd, $st] = array_pad(explode(' ', (string) $e->field('starts')), 2, '');
    [$ed, $et] = $e->field('ends') ? array_pad(explode(' ', (string) $e->field('ends')), 2, '') : ['', ''];

    return array_merge($blank, [
        'title' => (string) $e->field('title'), 'starts_date' => $sd, 'starts_time' => $e->field('all_day') ? '' : $st,
        'ends_date' => $ed === $sd ? '' : $ed, 'ends_time' => $e->field('all_day') ? '' : $et, 'all_day' => (bool) $e->field('all_day'),
        'venue' => (string) $e->field('venue'), 'address' => (string) $e->field('address'), 'about' => (string) $e->field('about'), 'link' => (string) $e->field('link'),
    ]);
};

return [
    'name' => 'Events',
    'about' => 'Things happening, with a date, a time and a place: a page at /events, “add to my calendar”, and a list of what’s coming in any post.',
    'version' => '1.0',
    'author' => 'Booklet',
    'sticker' => 'coral',
    'needs' => '1.0',

    'settings' => [
        'heading' => ['type' => 'text', 'label' => 'Name of the events page', 'default' => 'What’s on'],
        'intro' => ['type' => 'textarea', 'label' => 'A line at the top of it', 'default' => ''],
        'show_past' => ['type' => 'toggle', 'label' => 'Show past events too', 'hint' => 'Under the upcoming ones, newest first', 'default' => true],
        'forget_after' => ['type' => 'number', 'label' => 'Forget events after (days)', 'hint' => '0 keeps them all', 'default' => 0, 'min' => 0, 'max' => 3650],
    ],

    'page' => [
        'title' => 'Events',
        'chapter' => 'Posts',
        'left' => 'admin-left',
        'form' => $blank,

        'data' => function (Insert $insert, array $form, $editing) use ($sorted, $when) {
            [$upcoming, $past] = $sorted($insert);

            return ['upcoming' => $upcoming, 'past' => $past, 'event' => $insert->records('events')->find($editing), 'when' => $when];
        },

        'actions' => [
            'save' => function (Insert $insert, array $form, $payload, $editing) use ($rules, $names, $toRecord, $blank) {
                $data = $insert->validate($form, $rules, ['ends_date.after_or_equal' => 'It can’t end before it starts.'], $names);
                $events = $insert->records('events');
                if ($editing && ($event = $events->find($editing))) {
                    $events->update($event, $toRecord($data));

                    return 'Saved.';
                }
                $event = $events->create($toRecord($data));

                return ['editing' => null, 'form' => $blank, 'stamp' => '“'.$event->field('title').'” is on the list.'];
            },
            'open' => fn (Insert $insert, array $form, $id) => ($e = $insert->records('events')->find((int) $id)) ? ['editing' => $e->id, 'form' => $toForm($e)] : null,
            'new' => fn () => ['editing' => null, 'form' => $blank],
            'delete' => function (Insert $insert, array $form, $payload, $editing) use ($blank) {
                $insert->records('events')->delete((int) $editing);

                return ['editing' => null, 'form' => $blank, 'stamp' => 'Deleted.'];
            },
        ],
    ],

    // The events page, one event's calendar file, and a feed of them all.
    'routes' => function ($router, Insert $insert) use ($sorted, $ics, $when) {
        $router->get('/events', function () use ($insert, $sorted, $when) {
            [$upcoming, $past] = $sorted($insert);

            // Each event, for search engines (they can show the date and place right in the results).
            $site = app(\App\Booklet\Site::class);
            foreach ($upcoming->take(30) as $e) {
                [$start, $end] = $when($e);
                $allDay = (bool) $e->field('all_day');
                $place = trim((string) $e->field('venue'));
                $address = trim((string) $e->field('address'));
                \App\Support\StructuredData::add(array_filter([
                    '@type' => 'Event',
                    '@id' => $site->absolute(route('site.insert.events.index')).'#'.$e->slug,
                    'name' => $e->field('title'),
                    'startDate' => $allDay ? $start->format('Y-m-d') : $start->toIso8601String(),
                    'endDate' => $end ? ($allDay ? $end->format('Y-m-d') : $end->toIso8601String()) : null,
                    'eventStatus' => 'https://schema.org/EventScheduled',
                    'eventAttendanceMode' => $place !== '' || $address !== '' ? 'https://schema.org/OfflineEventAttendanceMode' : null,
                    'location' => $place !== '' || $address !== '' ? array_filter(['@type' => 'Place', 'name' => $place ?: $address, 'address' => $address ?: null]) : null,
                    'description' => $e->field('about') ? \Illuminate\Support\Str::limit((string) $e->field('about'), 500) : null,
                    'url' => $e->field('link') ?: $site->absolute(route('site.insert.events.index')).'#'.$e->slug,
                    'organizer' => ['@id' => \App\Support\StructuredData::id('publisher')],
                ]));
            }

            return view('insert-events::page', ['insert' => $insert, 'upcoming' => $upcoming, 'past' => $insert->setting('show_past') ? $past->take(20) : collect()]);
        })->name('index');

        $router->get('/events/{slug}.ics', function (string $slug) use ($insert, $ics) {
            $event = $insert->records('events')->findBySlug($slug);
            abort_unless($event !== null, 404);

            return response($ics(collect([$event]), $insert), 200, [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$event->slug.'.ics"',
            ]);
        })->where('slug', '[a-z0-9-]+')->name('ics');

        $router->get('/events.ics', function () use ($insert, $sorted, $ics) {
            return response($ics($sorted($insert)[0], $insert), 200, ['Content-Type' => 'text/calendar; charset=utf-8']);
        })->name('feed');
    },

    // [[events]] shows the next three; [[events: 5]] the next five.
    'slips' => [
        'events' => function (string $words, Insert $insert) use ($sorted) {
            $upcoming = $sorted($insert)[0]->take(max(1, min(12, (int) $words ?: 3)));

            return $insert->view('slip', ['upcoming' => $upcoming]);
        },
    ],
    'slip_help' => ['events' => '[[events]] or [[events: 5]]'],

    // A cut-out for blocks fields: the same list, tucked anywhere in a page.
    'blocks' => [
        'upcoming' => [
            'name' => 'Upcoming events',
            'about' => 'The next few events, soonest first.',
            'tone' => 'mint',
            'fields' => [
                'heading' => ['type' => 'text', 'display' => 'Heading', 'default' => 'Coming up'],
                'count' => ['type' => 'select', 'display' => 'How many', 'default' => '3', 'options' => ['3' => '3', '5' => '5', '8' => '8']],
            ],
            'show' => function (array $block, Insert $insert) use ($sorted) {
                $upcoming = $sorted($insert)[0]->take(max(1, min(12, (int) $block['count'] ?: 3)));

                return $insert->view('slip', ['upcoming' => $upcoming, 'heading' => $block['heading'] ?? null]);
            },
        ],
    ],

    'site' => ['css' => ['events.css']],

    // Its page, for the sitemap and plain files (Booklet 1.2; older ones ignore it).
    'pages' => ['/events'],

    // On the Contents page: what's on in the next week (nothing shows when nothing is).
    'contents' => [
        'this-week' => [
            'title' => 'Coming up this week',
            'show' => function (Insert $insert) use ($sorted, $when) {
                $soon = $sorted($insert)[0]->filter(fn ($e) => $when($e)[0]->lte(now()->addDays(7)->endOfDay()));
                if ($soon->isEmpty()) {
                    return null;
                }
                $page = route('admin.insert', $insert->key);

                return [
                    'count' => $soon->count(),
                    'items' => $soon->take(4)->map(function ($e) use ($when, $page) {
                        [$start] = $when($e);
                        $day = $start->isToday() ? 'Today' : ($start->isTomorrow() ? 'Tomorrow' : $start->format('D j M'));

                        return [
                            'label' => $e->field('title'),
                            'meta' => $day.($e->field('all_day') ? '' : ', '.$start->format('g:ia')).($e->field('venue') ? ' · '.$e->field('venue') : ''),
                            'href' => $page.'?open='.$e->id,
                        ];
                    })->values()->all(),
                    'link' => ['label' => $soon->count() > 4 ? 'All '.$soon->count() : 'All events', 'href' => $page],
                ];
            },
        ],
    ],

    // What Stacy can do with events.
    'stacy' => [
        'upcoming' => [
            'about' => 'List the events coming up (soonest first), or recent past ones too: title, when, where.',
            'label' => 'Tells you what’s coming up',
            'takes' => ['include_past' => ['type' => 'boolean', 'about' => 'true to add the ten most recent past events']],
            'look' => function (array $args, Insert $insert) use ($sorted, $when) {
                [$upcoming, $past] = $sorted($insert);
                $describe = function ($e) use ($when) {
                    [$start, $end] = $when($e);

                    return array_filter([
                        'title' => $e->field('title'),
                        'starts' => $start?->format($e->field('all_day') ? 'D j M Y' : 'D j M Y, H:i'),
                        'ends' => $end?->format($e->field('all_day') ? 'D j M Y' : 'D j M Y, H:i'),
                        'all_day' => (bool) $e->field('all_day') ?: null,
                        'venue' => $e->field('venue') ?: null,
                    ]);
                };
                $page = route('admin.insert', $insert->key);

                return array_filter([
                    'upcoming' => $upcoming->take(15)->map($describe)->values()->all(),
                    'past' => ! empty($args['include_past']) ? $past->take(10)->map($describe)->values()->all() : null,
                    'events_page' => route('site.insert.events.index'),
                    'links' => $upcoming->take(5)->map(fn ($e) => ['label' => $e->field('title'), 'meta' => $when($e)[0]?->format('j M') ?? 'Event', 'href' => $page.'?open='.$e->id])->values()->all(),
                ], fn ($v) => $v !== null);
            },
        ],
        'add' => [
            'about' => 'Propose adding an event to the events page. Needs a title and a date; ask for the date and start time if the person didn’t say.',
            'label' => 'Adds an event',
            'takes' => [
                'title' => ['type' => 'string'],
                'date' => ['type' => 'string', 'about' => 'Start date, YYYY-MM-DD'],
                'start_time' => ['type' => 'string', 'about' => 'HH:MM, 24-hour, in the site’s timezone (leave out for all day)'],
                'end_date' => ['type' => 'string', 'about' => 'YYYY-MM-DD, if it runs over several days'],
                'end_time' => ['type' => 'string', 'about' => 'HH:MM'],
                'all_day' => ['type' => 'boolean'],
                'venue' => ['type' => 'string', 'about' => 'Where it is, e.g. Tamu Kianggeh'],
                'address' => ['type' => 'string'],
                'about' => ['type' => 'string', 'about' => 'A few lines about it'],
                'link' => ['type' => 'string', 'about' => 'A web address for tickets or more, if any'],
            ],
            'needs' => ['title', 'date'],
            'propose' => function (array $args, Insert $insert) use ($rules, $names, $toRecord, $blank) {
                $form = array_merge($blank, [
                    'title' => (string) ($args['title'] ?? ''), 'starts_date' => (string) ($args['date'] ?? ''),
                    'starts_time' => (string) ($args['start_time'] ?? ''), 'ends_date' => (string) ($args['end_date'] ?? ''),
                    'ends_time' => (string) ($args['end_time'] ?? ''), 'all_day' => (bool) ($args['all_day'] ?? empty($args['start_time'])),
                    'venue' => (string) ($args['venue'] ?? ''), 'address' => (string) ($args['address'] ?? ''),
                    'about' => (string) ($args['about'] ?? ''), 'link' => (string) ($args['link'] ?? ''),
                ]);
                $data = $insert->validate($form, $rules, ['ends_date.after_or_equal' => 'It can’t end before it starts.'], $names);
                $record = $toRecord($data);
                $dupe = $insert->records('events')->all()->first(fn ($e) => mb_strtolower((string) $e->field('title')) === mb_strtolower($record['title'])
                    && str_starts_with((string) $e->field('starts'), $data['starts_date']));
                if ($dupe) {
                    return 'There’s already “'.$dupe->field('title').'” on that day.';
                }

                $start = Carbon::parse($record['starts']);
                $when = $record['all_day'] ? $start->format('l j F') : $start->format('l j F').' at '.$start->format('g:ia');

                return [
                    'summary' => 'Add “'.$record['title'].'” on '.$when.($record['venue'] !== '' ? ', at '.$record['venue'] : '').', to the events page.',
                    'button' => 'Yes, add it',
                    'data' => $record,
                ];
            },
            'do' => function (array $record, Insert $insert) {
                $event = $insert->records('events')->create(array_intersect_key($record, array_flip(['title', 'starts', 'ends', 'all_day', 'venue', 'address', 'about', 'link'])));

                return [
                    'text' => 'Done. “'.$event->field('title').'” is on the events page.',
                    'link' => ['label' => $event->field('title'), 'meta' => 'Event', 'href' => route('admin.insert', $insert->key).'?open='.$event->id],
                ];
            },
        ],
    ],

    'every' => [
        'daily' => function (Insert $insert) use ($when) {
            $days = (int) $insert->setting('forget_after', 0);
            if ($days <= 0) {
                return;
            }
            foreach ($insert->records('events')->all() as $e) {
                [$start, $end] = $when($e);
                if ($start && ($end ?? $start)->lt(now()->subDays($days))) {
                    $e->delete();
                }
            }
        },
    ],
];
