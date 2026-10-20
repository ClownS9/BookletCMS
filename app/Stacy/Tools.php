<?php

namespace App\Stacy;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * What Stacy can do. Looking things up happens straight away; anything that
 * changes the book only becomes a proposal, which runs when the person
 * presses "Yes" (see confirm()).
 */
class Tools
{
    public const PLACES = [
        'contents' => ['Contents', 'admin.contents', []],
        'posts' => ['Posts', 'admin.entries', ['posts']],
        'pages' => ['Pages', 'admin.entries', ['pages']],
        'new_post' => ['A new post', 'admin.entries.create', ['posts']],
        'media' => ['Media', 'admin.media', []],
        'people' => ['People', 'admin.people', []],
        'comments' => ['Comments', 'admin.comments', []],
        'calendar' => ['Calendar', 'admin.calendar', []],
        'activity' => ['Activity', 'admin.activity', []],
        'visits' => ['Visits', 'admin.visits', []],
        'collections' => ['Collections', 'admin.collections', []],
        'categories_and_tags' => ['Categories & tags', 'admin.taxonomies', []],
        'site_menus' => ['Site menus', 'admin.menus', []],
        'redirects' => ['Redirects', 'admin.redirects', []],
        'bin' => ['The bin', 'admin.bin', []],
        'backups' => ['Backups', 'admin.backups', []],
        'print' => ['Print as a book', 'admin.print', []],
        'import' => ['Move in from WordPress', 'admin.import', []],
        'subscribers' => ['Subscribers', 'admin.subscribers', []],
        'inbox' => ['Inbox', 'admin.inbox', []],
        'sticker_book' => ['Your sticker book', 'admin.account', [], 'stickers'],
        'new_page' => ['A new page', 'admin.entries.create', ['pages']],
        'settings' => ['Settings', 'admin.settings', []],
        'inserts' => ['Inserts', 'admin.settings.inserts', []],
        'email_settings' => ['Email settings', 'admin.settings.email', []],
        'health' => ['Check-up', 'admin.settings.health', []],
        'account' => ['Your account', 'admin.account', []],
        'stacy_settings' => ['Stacy’s brain', 'admin.settings.stacy', []],
        'stacy_behaviour' => ['How Stacy behaves', 'admin.settings.stacy.behaviour', []],
        'search' => ['Search everything', null, []], // the search window, not a page
    ];

    /** Other words people use for each place (the place's own name and label count too). */
    public const ALIASES = [
        'contents' => ['dashboard', 'home', 'start', 'front page', 'overview', 'first steps', 'getting started', 'checklist', 'what to do next'],
        'posts' => ['blog', 'articles', 'blog posts', 'post'],
        'pages' => ['page', 'page tree'],
        'new_post' => ['new post', 'write a post', 'a new post', 'blank post'],
        'media' => ['pictures', 'images', 'photos', 'files', 'uploads', 'album'],
        'people' => ['team', 'users', 'writers', 'members'],
        'comments' => ['notes', 'margin notes', 'replies'],
        'calendar' => ['schedule', 'scheduled posts'],
        'activity' => ['activity log', 'history', 'log', 'changes'],
        'visits' => ['stats', 'statistics', 'analytics', 'traffic', 'visitors', 'readers', 'most read'],
        'collections' => ['blueprints', 'chapters', 'collection'],
        'categories_and_tags' => ['categories', 'tags', 'category', 'index', 'taxonomies'],
        'site_menus' => ['menus', 'menu', 'navigation', 'site menu', 'header menu', 'footer menu'],
        'redirects' => ['redirect', 'forwarding', 'old links', 'broken links', '301', 'old address'],
        'bin' => ['trash', 'recycle bin', 'deleted', 'deleted items', 'undo delete', 'put back', 'bin'],
        'backups' => ['backup', 'back up', 'export', 'download everything', 'restore a backup', 'snapshot'],
        'settings' => ['setting', 'site settings', 'preferences'],
        'inserts' => ['inserts', 'addons', 'add-ons', 'plugins', 'extras', 'extensions'],
        'health' => ['check-up', 'checkup', 'health check', 'site health', 'server check', 'doctor', 'is everything working', 'cron', 'scheduler'],
        'email_settings' => ['email', 'smtp', 'mail settings', 'mail server', 'email settings', 'outgoing mail', 'gmail', 'emails not arriving'],
        'account' => ['my account', 'profile', 'my profile', 'password'],
        'stacy_settings' => ['stacy’s brain', 'stacys brain', 'ai settings', 'ai service', 'api key'],
        'stacy_behaviour' => ['stacy settings', 'stacy\'s settings', 'stacy’s settings', 'how stacy behaves', 'stacy behaviour', 'stacy behavior', 'your settings', 'answer length', 'daily limit'],
        'search' => ['search everything'],
        'inbox' => ['inbox', 'messages', 'contact form', 'contact messages', 'letters', 'emails from readers', 'mail from readers'],
        'subscribers' => ['subscribers', 'newsletter', 'mailing list', 'email list', 'readers by email'],
        'import' => ['import', 'wordpress', 'import from wordpress', 'move in', 'wordpress import', 'migrate'],
        'print' => ['print', 'print as a book', 'printing', 'make a book', 'book pdf', 'pdf of my posts'],
        'sticker_book' => ['stickers', 'sticker book', 'my stickers', 'milestones', 'badges'],
        'new_page' => ['new page', 'a new page', 'blank page'],
    ];

    /** Places only some people may open (the rest would meet a locked door). */
    public const ONLY = [
        'backups' => ['admin'],
        'inserts' => ['admin'],
        'import' => ['admin'],
        'subscribers' => ['admin', 'editor'],
        'inbox' => ['admin', 'editor'],
        'stacy_settings' => ['admin'],
        'email_settings' => ['admin'],
        'health' => ['admin'],
        'stacy_behaviour' => ['admin'],
        'site_menus' => ['admin', 'editor'],
        'redirects' => ['admin', 'editor'],
    ];

    /** Where to take the person straight away, if they asked to go somewhere. */
    public ?array $navigate = null;

    /** Links to show under Stacy's answer: ['label', 'meta', 'href', 'tone']. */
    public array $links = [];

    /** A change waiting for "Yes". */
    public ?array $pending = null;

    public function __construct(protected User $user) {}

    public function definitions(): array
    {
        $none = ['type' => 'object', 'properties' => (object) []];

        // Only the changes the admin allows in Settings › How Stacy behaves.
        return array_values(array_filter([...$this->allDefinitions($none), ...$this->insertDefinitions()], fn ($tool) => StacyConfig::allowsTool($tool['name'])
            && ($tool['name'] !== 'site_health' || $this->user->isAdmin())));
    }

    /** What switched-on inserts teach her, for the people each skill is for. */
    protected function insertDefinitions(): array
    {
        $defs = [];
        foreach (rescue(fn () => app(\App\Inserts\InsertManager::class)->skills(), [], true) as $tool => ['insert' => $insert, 'skill' => $skill]) {
            if (! in_array($this->user->level(), $skill['who'], true)) {
                continue;
            }
            $defs[] = [
                'name' => $tool,
                'description' => '['.$insert->name().' insert] '.$skill['about'].($skill['changes'] ? ' Nothing changes until the person confirms.' : ''),
                'parameters' => ['type' => 'object', 'properties' => $skill['takes'] ?: (object) []] + ($skill['needs'] ? ['required' => $skill['needs']] : []),
            ];
        }

        return $defs;
    }

    /** Run an insert's skill: a look-up, or a change offered for "Yes". */
    protected function runSkill(ToolCall $call): array
    {
        $found = app(\App\Inserts\InsertManager::class)->skills()[$call->name] ?? null;
        if (! $found || ! in_array($this->user->level(), $found['skill']['who'], true)) {
            return ['error' => "There is no tool called {$call->name}."];
        }
        ['insert' => $insert, 'skill' => $skill] = $found;
        $args = array_intersect_key((array) $call->arguments, $skill['takes']);
        foreach ($skill['needs'] as $need) {
            if (! isset($args[$need]) || $args[$need] === '') {
                return ['error' => "It needs {$need}."];
            }
        }

        try {
            $out = $skill['changes'] ? ($skill['propose'])($args, $insert, $this->user) : ($skill['look'])($args, $insert, $this->user);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['error' => implode(' ', \Illuminate\Support\Arr::flatten($e->errors())).' Ask the person, or fix it and try again.'];
        } catch (Throwable $e) {
            report($e);
            app(\App\Inserts\InsertManager::class)->noteError($insert->key, $e);

            return ['error' => 'The '.$insert->name().' insert had a problem with that. Say so plainly; an admin can see what went wrong in Settings › Inserts.'];
        }

        if (is_string($out)) {
            return $skill['changes'] ? ['error' => $out] : ['result' => $out]; // a change that can't be offered says why
        }
        $out = (array) $out;
        foreach ((array) ($out['links'] ?? []) as $link) {
            if (is_array($link) && ($href = self::safeHref($link['href'] ?? null)) && ! empty($link['label'])) {
                $this->links[] = ['label' => Str::limit((string) $link['label'], 80), 'meta' => Str::limit((string) ($link['meta'] ?? $insert->name()), 40), 'href' => $href, 'tone' => 'var(--'.$insert->sticker().')'];
            }
        }
        unset($out['links']);

        if (! $skill['changes']) {
            return $out;
        }

        $summary = trim((string) ($out['summary'] ?? ''));
        $data = $out['data'] ?? [];
        if ($summary === '' || json_encode($data) === false) {
            return ['error' => 'The '.$insert->name().' insert didn’t say what it would do, so nothing was offered.'];
        }
        $this->pending = [
            'action' => 'insert', 'insert' => $insert->key, 'skill' => $skill['name'], 'data' => $data,
            'summary' => Str::limit($summary, 400), 'button' => Str::limit((string) ($out['button'] ?? 'Yes, go ahead'), 40),
        ];

        return ['status' => 'waiting_for_confirmation', 'summary' => $this->pending['summary'], 'note' => 'Ask the person to confirm with the button under your message.'];
    }

    /** Only addresses in this site, or on the web: never javascript: and the like. */
    protected static function safeHref(mixed $href): ?string
    {
        $href = is_string($href) ? trim($href) : '';

        return $href !== '' && (preg_match('#^https?://#i', $href) || (str_starts_with($href, '/') && ! str_starts_with($href, '//'))) ? $href : null;
    }

    protected function allDefinitions(array $none): array
    {
        return [
            [
                'name' => 'search_content',
                'description' => 'Find posts, pages and other entries by words in their title or address. Returns id, title, collection, status and date.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Words to look for'],
                ], 'required' => ['query']],
            ],
            [
                'name' => 'list_drafts',
                'description' => 'List entries that are still drafts, most recently edited first.',
                'parameters' => $none,
            ],
            [
                'name' => 'list_scheduled',
                'description' => 'List entries scheduled to go out in the future, soonest first.',
                'parameters' => $none,
            ],
            [
                'name' => 'site_overview',
                'description' => 'Counts of entries per collection, drafts, files in Media and comments waiting.',
                'parameters' => $none,
            ],
            [
                'name' => 'who_is_online',
                'description' => 'People in the book right now, and everyone else with when they were last seen.',
                'parameters' => $none,
            ],
            [
                'name' => 'go_to',
                'description' => 'Open a part of the admin for the person. With open=true (when they asked to go, open, show or be taken somewhere) the page opens by itself right after your reply; with open=false it only shows a link under your message.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'place' => ['type' => 'string', 'enum' => array_keys(static::places())],
                    'open' => ['type' => 'boolean', 'description' => 'true to take them there now'],
                ], 'required' => ['place']],
            ],
            [
                'name' => 'list_comments',
                'description' => 'Comments from readers. Defaults to the ones waiting for approval. Returns id, who wrote it, the start of the note, which entry it is on and when.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['waiting', 'approved', 'spam']],
                ]],
            ],
            [
                'name' => 'recent_activity',
                'description' => 'What changed in the book lately: who did what to which entry, newest first.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'How many, up to 20'],
                ]],
            ],
            [
                'name' => 'site_visits',
                'description' => 'Visits to the public site this week: views per day, roughly how many readers, the most-read pages, how many read to the end, and where readers came from.',
                'parameters' => $none,
            ],
            [
                'name' => 'find_media',
                'description' => 'Find pictures and files in Media by file name or alt text. Also says which pictures have no alt text when asked with query "no alt".',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string'],
                ], 'required' => ['query']],
            ],
            [
                'name' => 'list_terms',
                'description' => 'The blog\'s categories and tags, with how many entries use each.',
                'parameters' => $none,
            ],
            [
                'name' => 'read_entry',
                'description' => 'Read one entry: its title, status, date, address, standfirst, categories, tags, the start of its text, and (if published) how it has been read in the last 30 days. Use before suggesting edits, or when asked how a post is doing.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'entry_id' => ['type' => 'string'],
                ], 'required' => ['entry_id']],
            ],
            [
                'name' => 'propose_unpublish',
                'description' => 'Propose taking a published or scheduled entry off the site and back to a draft. Nothing changes until the person confirms.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'entry_id' => ['type' => 'string'],
                ], 'required' => ['entry_id']],
            ],
            [
                'name' => 'propose_edit',
                'description' => 'Propose changing an entry\'s title, standfirst (the short line under the title), and/or its SEO meta title and meta description (what search engines show; meta description about 150 characters). Nothing changes until the person confirms.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'entry_id' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'standfirst' => ['type' => 'string'],
                    'meta_title' => ['type' => 'string'],
                    'meta_description' => ['type' => 'string'],
                ], 'required' => ['entry_id']],
            ],
            [
                'name' => 'check_book',
                'description' => 'A check-up of the whole book: stale drafts, published posts missing a standfirst, tags or meta description, pictures without alt text, pages not in the site menu, comments waiting, and what goes out this week. Use for "what needs attention?" or "check my site".',
                'parameters' => $none,
            ],
            [
                'name' => 'site_health',
                'description' => 'The server check-up (admins only): whether the scheduler runs, email goes out, backups are made, folders are writable, the database is up to date, and so on, with how to fix what isn’t. Use for “is everything working?”, “why didn’t my scheduled post go out?”, “emails aren’t arriving”.',
                'parameters' => $none,
            ],
            [
                'name' => 'daily_brief',
                'description' => 'A short briefing for today: visits today and yesterday, comments waiting, what is scheduled today and this week, drafts edited lately, and who is online. Use for "brief me", "good morning", "what\'s new?".',
                'parameters' => $none,
            ],
            [
                'name' => 'propose_tags',
                'description' => 'Propose setting an entry\'s category and tags (blog posts). New tags are created if they do not exist. Read the entry and list_terms first, and prefer existing tags. Nothing changes until the person confirms.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'entry_id' => ['type' => 'string'],
                    'category' => ['type' => 'string', 'description' => 'One category title, optional'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '2 to 5 short tags'],
                    'replace' => ['type' => 'boolean', 'description' => 'true to replace existing tags, false (default) to add to them'],
                ], 'required' => ['entry_id']],
            ],
            [
                'name' => 'propose_alt',
                'description' => 'Propose alt text for a picture in Media (what a screen reader says; one plain sentence describing the picture, judged from its file name and where it is used). Nothing changes until the person confirms.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'file' => ['type' => 'string', 'description' => 'The file path as find_media returns it'],
                    'alt' => ['type' => 'string'],
                ], 'required' => ['file', 'alt']],
            ],
            [
                'name' => 'propose_translation',
                'description' => 'Propose making a translated copy of an entry: a new draft in the same chapter, in another language, with the same pictures. Nothing is made until the person confirms; the original is never changed.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'entry_id' => ['type' => 'string'],
                    'language' => ['type' => 'string', 'enum' => array_keys(Writer::LANGUAGES), 'description' => 'Language code: '.collect(Writer::LANGUAGES)->map(fn ($l, $code) => "{$code} = {$l[0]}")->implode(', ')],
                ], 'required' => ['entry_id', 'language']],
            ],
            [
                'name' => 'propose_reply',
                'description' => 'Propose replying to a reader\'s comment, posted as the person you are helping. Write warmly and briefly in their voice. Use list_comments first. Nothing is posted until the person confirms.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'comment_id' => ['type' => 'integer'],
                    'text' => ['type' => 'string'],
                ], 'required' => ['comment_id', 'text']],
            ],
            [
                'name' => 'propose_comment',
                'description' => 'Propose approving a reader\'s comment, marking it as spam, or deleting it. Nothing changes until the person confirms.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'comment_id' => ['type' => 'integer'],
                    'action' => ['type' => 'string', 'enum' => ['approve', 'spam', 'delete']],
                ], 'required' => ['comment_id', 'action']],
            ],
            [
                'name' => 'propose_schedule',
                'description' => 'Propose scheduling an entry to publish at a future date and time. Nothing changes until the person confirms.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'entry_id' => ['type' => 'string'],
                    'when' => ['type' => 'string', 'description' => 'Date and time, e.g. 2026-10-17 09:00, in the site’s timezone'],
                ], 'required' => ['entry_id', 'when']],
            ],
            [
                'name' => 'propose_publish',
                'description' => 'Propose publishing an entry now. Nothing changes until the person confirms.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'entry_id' => ['type' => 'string'],
                ], 'required' => ['entry_id']],
            ],
            [
                'name' => 'propose_draft',
                'description' => 'Propose starting a new draft: a title, and optionally a standfirst, opening text in Markdown (an outline or first paragraphs when asked to write), tags, and for pages a parent page. Nothing is created until the person confirms.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'collection' => ['type' => 'string', 'description' => 'Collection handle, e.g. posts'],
                    'title' => ['type' => 'string'],
                    'standfirst' => ['type' => 'string'],
                    'content' => ['type' => 'string', 'description' => 'Optional opening text, in Markdown'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'parent_id' => ['type' => 'string', 'description' => 'For pages: the id of the page it goes under'],
                ], 'required' => ['collection', 'title']],
            ],
        ];
    }

    /**
     * Run one tool call and return what the model should read (JSON).
     */
    public function run(ToolCall $call): string
    {
        if (! StacyConfig::allowsTool($call->name)) {
            return json_encode(['error' => 'The admin has switched that kind of change off for Stacy. Say so plainly and suggest doing it by hand in the right chapter.']);
        }

        try {
            $result = str_contains($call->name, '__') ? $this->runSkill($call) : match ($call->name) {
                'search_content' => $this->searchContent((string) ($call->arguments['query'] ?? '')),
                'list_drafts' => $this->entries(fn (Entry $e) => ! $e->published() && ($this->canPublish($e) || $this->owns($e)), 'drafts'),
                'list_scheduled' => $this->entries(fn (Entry $e) => $e->status() === 'scheduled', 'scheduled'),
                'site_overview' => $this->overview(),
                'who_is_online' => $this->people(),
                'go_to' => $this->goTo((string) ($call->arguments['place'] ?? ''), (bool) ($call->arguments['open'] ?? true)),
                'propose_schedule' => $this->proposeSchedule((string) ($call->arguments['entry_id'] ?? ''), (string) ($call->arguments['when'] ?? '')),
                'propose_publish' => $this->proposePublish((string) ($call->arguments['entry_id'] ?? '')),
                'propose_draft' => $this->proposeDraft($call->arguments),
                'list_comments' => $this->comments((string) ($call->arguments['status'] ?? 'waiting')),
                'recent_activity' => $this->activity((int) ($call->arguments['limit'] ?? 8)),
                'site_visits' => $this->visits(),
                'find_media' => $this->media((string) ($call->arguments['query'] ?? '')),
                'list_terms' => $this->terms(),
                'read_entry' => $this->readEntry((string) ($call->arguments['entry_id'] ?? '')),
                'propose_unpublish' => $this->proposeUnpublish((string) ($call->arguments['entry_id'] ?? '')),
                'propose_edit' => $this->proposeEdit($call->arguments),
                'propose_comment' => $this->proposeComment((int) ($call->arguments['comment_id'] ?? 0), (string) ($call->arguments['action'] ?? '')),
                'check_book' => $this->checkBook(),
                'daily_brief' => $this->dailyBrief(),
                'site_health' => $this->siteHealth(),
                'propose_tags' => $this->proposeTags($call->arguments),
                'propose_alt' => $this->proposeAlt((string) ($call->arguments['file'] ?? ''), (string) ($call->arguments['alt'] ?? '')),
                'propose_reply' => $this->proposeReply((int) ($call->arguments['comment_id'] ?? 0), (string) ($call->arguments['text'] ?? '')),
                'propose_translation' => $this->proposeTranslation((string) ($call->arguments['entry_id'] ?? ''), (string) ($call->arguments['language'] ?? '')),
                default => ['error' => "There is no tool called {$call->name}."],
            };
        } catch (Throwable $e) {
            report($e);
            $result = ['error' => 'That didn’t work: '.$e->getMessage()];
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{"error":"The result could not be read."}';
    }

    // Looking things up ----------------------------------------------------

    protected function describe(Entry $e): array
    {
        return [
            'id' => $e->id(),
            'title' => $e->title() ?: 'Untitled',
            'collection' => $e->collectionHandle(),
            'status' => $e->status(),
            'date' => $e->date()?->format('Y-m-d H:i'),
            'author_is_you' => $this->owns($e),
        ];
    }

    protected function canPublishAny(): bool
    {
        return in_array($this->user->level(), ['admin', 'editor'], true);
    }

    /** Is it in a chapter this person's role opens? (Others' chapters stay closed to Stacy too.) */
    protected function opens(Entry $e): bool
    {
        return $this->user->canOpen($e->collectionHandle());
    }

    protected function owns(Entry $e): bool
    {
        return (string) $e->get('author') === (string) $this->user->id;
    }

    protected function link(Entry $e): void
    {
        // Writers can only open their own entries in the editor; others open in the list.
        $href = $this->canPublish($e) || ($this->owns($e) && $this->opens($e))
            ? route('admin.entries.edit', [$e->collectionHandle(), $e->id()])
            : route('admin.entries', [$e->collectionHandle(), 'entry' => $e->id()]);

        $this->links[] = [
            'label' => $e->title() ?: 'Untitled',
            'meta' => ucfirst($e->status()).($e->date() ? ' · '.$e->date()->format('j M') : ''),
            'href' => $href,
            'tone' => ['draft' => 'var(--sun)', 'scheduled' => 'var(--sky)', 'published' => 'var(--mint)'][$e->status()],
        ];
    }

    protected function searchContent(string $query): array
    {
        $needle = Str::lower(trim($query));
        $found = Booklet::entries()->all()
            ->filter(fn (Entry $e) => $this->opens($e) && ($needle === '' || Str::contains(Str::lower($e->title().' '.$e->slug()), $needle)))
            ->sortByDesc(fn (Entry $e) => $e->lastModified())
            ->take(8)
            ->values();

        $found->each(fn (Entry $e) => $this->link($e));

        return ['results' => $found->map(fn (Entry $e) => $this->describe($e))->all()];
    }

    protected function entries(callable $filter, string $label): array
    {
        $found = Booklet::entries()->all()->filter(fn (Entry $e) => $this->opens($e) && $filter($e))
            ->sortBy(fn (Entry $e) => $label === 'scheduled' ? $e->attribute('date') : -strtotime((string) $e->lastModified()))
            ->take(10)
            ->values();

        $found->each(fn (Entry $e) => $this->link($e));

        return [$label => $found->map(fn (Entry $e) => $this->describe($e))->all()];
    }

    protected function overview(): array
    {
        $entries = Booklet::entries()->all();

        return [
            'collections' => Booklet::collections()->all()->mapWithKeys(fn ($c) => [
                $c->title() => $entries->filter(fn (Entry $e) => $e->collectionHandle() === $c->handle())->count(),
            ]),
            'drafts' => $entries->filter(fn (Entry $e) => ! $e->published())->count(),
            'scheduled' => $entries->filter(fn (Entry $e) => $e->status() === 'scheduled')->count(),
            'files_in_media' => Booklet::assets()->count(),
            'comments_waiting' => Comment::where('status', 'waiting')->count(),
            'people' => User::count(),
        ];
    }

    protected function people(): array
    {
        $people = User::orderByDesc('last_seen_at')->get();

        foreach ($people->filter->isOnline() as $person) {
            $this->links[] = [
                'label' => $person->name, 'meta' => 'online', 'tone' => 'var(--online)',
                'href' => route('admin.people', ['person' => $person->id]),
            ];
        }

        return ['people' => $people->map(fn (User $u) => [
            'name' => $u->name,
            'role' => $u->roleName(),
            'presence' => $u->presenceLabel(),
        ])->all()];
    }

    /**
     * Every place, with the pages of inserts that are switched on
     * (insert_gallery …) added to the book's own.
     *
     * @return array<string, array>
     */
    public static function places(): array
    {
        $places = self::PLACES;
        foreach (rescue(fn () => app(\App\Inserts\InsertManager::class)->active(), collect(), false) as $insert) {
            if ($insert->page()) {
                $places['insert_'.str_replace('-', '_', $insert->key)] = [$insert->page()['title'], 'admin.insert', [$insert->key]];
            }
        }

        return $places;
    }

    /** @return array<string, string[]> who may open the places that not everyone can */
    public static function only(): array
    {
        $only = self::ONLY;
        foreach (rescue(fn () => app(\App\Inserts\InsertManager::class)->active(), collect(), false) as $insert) {
            if ($insert->page()) {
                $only['insert_'.str_replace('-', '_', $insert->key)] = $insert->page()['who'];
            }
        }

        return $only;
    }

    /** @return array<string, string[]> */
    public static function aliases(): array
    {
        $aliases = self::ALIASES;
        foreach (rescue(fn () => app(\App\Inserts\InsertManager::class)->active(), collect(), false) as $insert) {
            if ($insert->page()) {
                $aliases['insert_'.str_replace('-', '_', $insert->key)] = array_values(array_unique([mb_strtolower($insert->name()), mb_strtolower($insert->page()['title'])]));
            }
        }

        return $aliases;
    }

    public function goTo(string $place, bool $open = true): array
    {
        $places = static::places();
        $only = static::only();
        if (! isset($places[$place])) {
            return ['error' => 'Unknown place. Choose one of: '.implode(', ', array_keys($places))];
        }

        if (isset($only[$place]) && ! in_array($this->user->level(), $only[$place], true)) {
            $who = $only[$place] === ['admin'] ? 'admins' : 'editors and admins';

            return ['error' => $places[$place][0].' is only for '.$who.'. Say so kindly; this person is '.($this->user->level() === 'admin' ? 'an admin' : 'a '.$this->user->roleName()).'.'];
        }

        [$label, $route, $params] = $places[$place];
        $fragment = isset($places[$place][3]) ? '#'.$places[$place][3] : '';
        // Search is a window over the book: "#search" opens it wherever you are.
        $href = $route ? route($route, $params).$fragment : route('admin.contents').'#search';
        $this->links[] = ['label' => $label, 'meta' => 'Open', 'href' => $href, 'tone' => 'var(--sand)'];

        if ($open) {
            $this->navigate = ['label' => $label, 'href' => $href];

            return ['opening' => $label, 'note' => 'The page opens by itself after your reply. Just say you are taking them there, in a few words.'];
        }

        return ['link_shown' => $label];
    }

    /**
     * "Take me to Pages", "open media", "bring me to the calendar":
     * the place they mean, without asking the AI. Null for anything else.
     */
    public static function placeFor(string $text): ?string
    {
        $text = trim(mb_strtolower($text), " \t\n.!?,");
        $text = preg_replace('/^(hey |ok |okay )?stac(y|ey|ie|i)[,\s]+/u', '', $text);

        if (! preg_match('/^(?:can you |could you |please |pls )*(?:take me|bring me|send me|get me|go|open|show me|show|jump|head|navigate|switch)(?: back)?(?: over)?(?: to| into)?(?: the| my| our)?\s+(.+?)(?: page| chapter| section| screen| tab)?(?: please| pls| now)?$/u', $text, $m)) {
            return null;
        }

        $wanted = trim($m[1]);

        $aliases = static::aliases();
        foreach (static::places() as $place => [$label]) {
            $names = array_merge([str_replace('_', ' ', $place), mb_strtolower($label)], $aliases[$place] ?? []);
            if (in_array($wanted, $names, true)) {
                return $place;
            }
        }

        return null;
    }

    protected function comments(string $status): array
    {
        $status = in_array($status, ['waiting', 'approved', 'spam'], true) ? $status : 'waiting';
        // Readers' replies show up too until they're approved (then they're part of their conversation).
        $comments = Comment::where('status', $status)->where(fn ($q) => $q->whereNull('parent_id')->orWhere('status', '!=', 'approved'))
            ->with('parent')->latest()->take(10)->get();

        $this->links[] = ['label' => 'Comments', 'meta' => ucfirst($status).' · '.$comments->count(), 'href' => route('admin.comments', ['status' => $status]), 'tone' => 'var(--rose)'];

        return ['status' => $status, 'comments' => $comments->map(fn (Comment $c) => [
            'id' => $c->id,
            'from' => $c->author_name,
            'note' => Str::limit((string) $c->body, 140),
            'on' => $c->entry()?->title(),
            'when' => $c->created_at?->diffForHumans(),
            'replying_to' => $c->parent ? $c->parent->author_name.': '.Str::limit((string) $c->parent->body, 80) : null,
            'wants_reply_emails' => $c->notify ?: null,
        ])->map(fn ($c) => array_filter($c, fn ($v) => $v !== null))->all()];
    }

    protected function activity(int $limit): array
    {
        $rows = ActivityLog::with('user')->latest()->take(max(1, min(20, $limit)))->get();
        $this->links[] = ['label' => 'Activity', 'meta' => 'Open', 'href' => route('admin.activity'), 'tone' => 'var(--coral)'];

        return ['activity' => $rows->map(fn (ActivityLog $a) => [
            'who' => $a->actor === 'stacy' ? 'Stacy (for '.($a->user?->name ?? 'someone').')' : ($a->user?->name ?? 'Someone'),
            'did' => trim($a->action.' '.$a->subject_label),
            'when' => $a->created_at?->diffForHumans(),
        ])->all()];
    }

    protected function visits(): array
    {
        $days = collect(range(6, 0))->map(fn ($ago) => now()->subDays($ago)->toDateString());

        try {
            $counts = \Illuminate\Support\Facades\DB::table('site_visits')->whereIn('day', $days)->pluck('views', 'day');
        } catch (Throwable) {
            $counts = collect();
        }

        $byDay = $days->mapWithKeys(fn ($d) => [Carbon::parse($d)->format('D j M') => (int) ($counts[$d] ?? 0)]);

        $week = rescue(fn () => \App\Support\VisitStats::summary(7), null, false);
        $this->links[] = ['label' => 'Visits', 'meta' => 'Open', 'href' => route('admin.visits'), 'tone' => 'var(--sky)'];

        return [
            'views_per_day' => $byDay->all(),
            'total_this_week' => $byDay->sum(),
            'readers_this_week_about' => $week['visitors'] ?? null,
            'read_to_the_end' => $week['reads'] ?? null,
            'most_read' => collect($week['pages'] ?? [])->take(5)->map(fn ($p) => [
                'title' => ($p['entry_id'] ? Booklet::entries()->find($p['entry_id'])?->title() : null) ?: $p['path'],
                'views' => $p['views'],
            ])->all(),
            'where_from' => collect($week['sources'] ?? [])->take(5)->all(),
            'note' => 'Counts public page views, not signed-in people. No cookies.',
        ];
    }

    protected function media(string $query): array
    {
        $needle = Str::lower(trim($query));
        $missingAlt = in_array($needle, ['no alt', 'without alt', 'missing alt', 'no alt text'], true);

        $found = Booklet::assets()->all()
            ->filter(fn ($a) => $missingAlt
                ? ! $a->get('alt') && preg_match('/\.(jpe?g|png|gif|webp|avif|svg)$/i', $a->path())
                : Str::contains(Str::lower($a->path().' '.$a->get('alt')), $needle))
            ->take(12)
            ->values();

        foreach ($found->take(6) as $asset) {
            $this->links[] = [
                'label' => basename($asset->path()),
                'meta' => $asset->get('alt') ? 'Has alt text' : 'No alt text',
                'href' => route('admin.media', ['in' => $asset->container(), 'file' => $asset->path()]),
                'tone' => 'var(--sky)',
            ];
        }

        return ['files' => $found->map(fn ($a) => [
            'file' => $a->path(),
            'alt' => $a->get('alt'),
            'size_kb' => $a->get('size') ? round($a->get('size') / 1024) : null,
        ])->all()];
    }

    protected function terms(): array
    {
        $counts = ['categories' => [], 'tags' => []];
        foreach (Booklet::entries()->all() as $entry) {
            foreach (['categories', 'tags'] as $taxonomy) {
                foreach ((array) $entry->get($taxonomy, []) as $slug) {
                    $counts[$taxonomy][$slug] = ($counts[$taxonomy][$slug] ?? 0) + 1;
                }
            }
        }

        $this->links[] = ['label' => 'Categories & tags', 'meta' => 'Open', 'href' => route('admin.taxonomies'), 'tone' => 'var(--rose)'];

        $list = fn (string $taxonomy) => Booklet::terms()->whereTaxonomy($taxonomy)
            ->map(fn ($t) => ['name' => $t->title(), 'used_on' => $counts[$taxonomy][$t->slug()] ?? 0])
            ->sortByDesc('used_on')->values()->all();

        return ['categories' => $list('categories'), 'tags' => $list('tags')];
    }

    protected function readEntry(string $id): array
    {
        $entry = Booklet::entries()->find($id);
        if (! $entry || ! $this->opens($entry)) {
            return ['error' => $entry ? 'That’s in a chapter this person’s role doesn’t open.' : 'No entry with that id. Use search_content to find it first.'];
        }

        $this->link($entry);

        return array_merge($this->describe($entry), [
            'address' => $entry->uri(),
            'standfirst' => $entry->get('excerpt'),
            'categories' => (array) $entry->get('categories', []),
            'tags' => (array) $entry->get('tags', []),
            'words' => \App\Support\Milestones::words($entry->get('content')),
            'opening' => Str::limit(trim(strip_tags(Str::markdown((string) $entry->get('content', '')))), 600),
            'reading_last_30_days' => $entry->status() === 'published' ? rescue(function () use ($entry) {
                $r = \App\Support\VisitStats::forEntry($entry->id());

                return ['views' => $r['views'], 'readers' => $r['readers'], 'read_to_the_end_percent' => $r['rate'],
                    'views_in_the_30_days_before' => $r['previous'], 'came_from' => $r['sources'], 'views_ever' => $r['total']];
            }, null, false) : null,
        ]);
    }

    public function canChange(Entry $entry): bool
    {
        return $this->canPublish($entry) || ($this->opens($entry) && $this->owns($entry) && ! $entry->published());
    }

    protected function proposeUnpublish(string $id): array
    {
        $entry = Booklet::entries()->find($id);
        if (! $entry) {
            return ['error' => 'No entry with that id. Use search_content to find it first.'];
        }
        if (! $this->canPublish($entry)) {
            return ['error' => 'Only people who publish in this chapter can take entries off the site.'];
        }
        if (! $entry->published()) {
            return ['error' => 'That entry is already a draft.'];
        }

        $summary = 'Take “'.($entry->title() ?: 'Untitled').'” off the site and back to a draft.';
        $this->pending = ['action' => 'unpublish', 'entry_id' => $id, 'summary' => $summary, 'button' => 'Yes, unpublish it'];
        $this->link($entry);

        return ['status' => 'waiting_for_confirmation', 'summary' => $summary, 'note' => 'Ask the person to confirm with the button under your message.'];
    }

    protected function proposeEdit(array $args): array
    {
        $entry = Booklet::entries()->find((string) ($args['entry_id'] ?? ''));
        if (! $entry) {
            return ['error' => 'No entry with that id. Use search_content to find it first.'];
        }
        if (! $this->canChange($entry)) {
            return ['error' => 'This person can only change their own drafts.'];
        }

        $changes = array_filter([
            'title' => isset($args['title']) ? trim(Str::limit((string) $args['title'], 200, '')) : null,
            'excerpt' => isset($args['standfirst']) ? trim(Str::limit((string) $args['standfirst'], 400, '')) : null,
            'meta_title' => isset($args['meta_title']) ? trim(Str::limit((string) $args['meta_title'], 70, '')) : null,
            'meta_description' => isset($args['meta_description']) ? trim(Str::limit((string) $args['meta_description'], 170, '')) : null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($changes === []) {
            return ['error' => 'Say what to change: a new title, standfirst, meta title or meta description.'];
        }

        $parts = [];
        if (isset($changes['title'])) {
            $parts[] = 'retitle it “'.$changes['title'].'”';
        }
        if (isset($changes['excerpt'])) {
            $parts[] = 'set its standfirst to “'.Str::limit($changes['excerpt'], 120).'”';
        }
        if (isset($changes['meta_title'])) {
            $parts[] = 'set its search title to “'.$changes['meta_title'].'”';
        }
        if (isset($changes['meta_description'])) {
            $parts[] = 'set its search description to “'.$changes['meta_description'].'”';
        }

        $summary = 'For “'.($entry->title() ?: 'Untitled').'”: '.implode(' and ', $parts).'.';
        $this->pending = ['action' => 'edit', 'entry_id' => $entry->id(), 'changes' => $changes, 'summary' => $summary, 'button' => 'Yes, change it'];
        $this->link($entry);

        return ['status' => 'waiting_for_confirmation', 'summary' => $summary, 'note' => 'Ask the person to confirm with the button under your message.'];
    }

    protected function proposeComment(int $id, string $action): array
    {
        $comment = Comment::find($id);
        if (! $comment) {
            return ['error' => 'No comment with that id. Use list_comments first.'];
        }
        if (! in_array($action, ['approve', 'spam', 'delete'], true)) {
            return ['error' => 'Choose approve, spam or delete.'];
        }

        $verb = ['approve' => 'Approve', 'spam' => 'Mark as spam', 'delete' => 'Delete'][$action];
        $summary = $verb.' the note from '.$comment->author_name.' on “'.($comment->entry()?->title() ?? 'an entry').'”: “'.Str::limit((string) $comment->body, 80).'”.';
        $this->pending = ['action' => 'comment', 'comment_id' => $id, 'do' => $action, 'summary' => $summary, 'button' => 'Yes, '.Str::lower($verb)];

        return ['status' => 'waiting_for_confirmation', 'summary' => $summary, 'note' => 'Ask the person to confirm with the button under your message.'];
    }

    // Proposing changes ----------------------------------------------------

    public function canPublish(Entry $entry): bool
    {
        return $this->user->canPublishIn($entry->collectionHandle());
    }

    protected function proposeSchedule(string $id, string $when): array
    {
        $entry = Booklet::entries()->find($id);
        if (! $entry) {
            return ['error' => 'No entry with that id. Use search_content to find it first.'];
        }
        if (! $this->canPublish($entry)) {
            return ['error' => 'This person is a writer, so they can’t schedule posts. An editor or admin can.'];
        }

        if (! $entry->collection()?->dated()) {
            // Pages have no date: "scheduling" one would put it live straight away.
            return ['error' => 'Entries in '.($entry->collection()?->title() ?? 'this chapter').' have no dates, so they can’t be scheduled; they go live when published. Say so, and offer to publish it now instead (or later, when they ask).'];
        }

        try {
            $at = Carbon::parse($when, config('app.timezone'))->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return ['error' => 'I couldn’t read that date. Use a format like 2026-10-17 09:00.'];
        }

        if ($at->isPast()) {
            return ['error' => 'That time has already passed. Pick a future time, or propose publishing now.'];
        }

        $summary = 'Schedule “'.($entry->title() ?: 'Untitled').'” for '.$at->format('l j F \a\t g:i a').'.';
        $this->pending = ['action' => 'schedule', 'entry_id' => $id, 'when' => $at->format('Y-m-d H:i:s'), 'summary' => $summary, 'button' => 'Yes, schedule it'];
        $this->link($entry);

        return ['status' => 'waiting_for_confirmation', 'summary' => $summary, 'note' => 'Ask the person to confirm with the button under your message.'];
    }

    protected function proposePublish(string $id): array
    {
        $entry = Booklet::entries()->find($id);
        if (! $entry) {
            return ['error' => 'No entry with that id. Use search_content to find it first.'];
        }
        if (! $this->canPublish($entry)) {
            return ['error' => 'This person is a writer, so they can’t publish. An editor or admin can.'];
        }

        if ($entry->status() === 'published') {
            return ['error' => 'That entry is already live on the site. Nothing to publish.'];
        }

        $summary = 'Publish “'.($entry->title() ?: 'Untitled').'” now.';
        $this->pending = ['action' => 'publish', 'entry_id' => $id, 'summary' => $summary, 'button' => 'Yes, publish it'];
        $this->link($entry);

        return ['status' => 'waiting_for_confirmation', 'summary' => $summary, 'note' => 'Ask the person to confirm with the button under your message.'];
    }

    protected function proposeDraft(array $args): array
    {
        $collection = (string) ($args['collection'] ?? '');
        $title = trim((string) ($args['title'] ?? ''));

        if (! Booklet::collections()->find($collection)) {
            return ['error' => 'No collection with that handle. Existing: '.Booklet::collections()->all()->map->handle()->implode(', ')];
        }
        if (! $this->user->canOpen($collection)) {
            return ['error' => 'This person’s role doesn’t open that chapter, so they can’t start a draft there.'];
        }
        if ($title === '') {
            return ['error' => 'A draft needs a title.'];
        }

        $model = Booklet::collections()->find($collection);
        $parent = null;
        if (! empty($args['parent_id']) && $model->structured()) {
            $parent = Booklet::entries()->find((string) $args['parent_id']);
            if (! $parent || $parent->collectionHandle() !== $collection) {
                return ['error' => 'No page with that id to put it under. Use search_content first.'];
            }
        }
        $tags = $this->cleanTags((array) ($args['tags'] ?? []));
        $content = (string) ($args['content'] ?? '');

        $summary = "Start a draft called “{$title}” in ".$model->title()
            .($parent ? ', under “'.$parent->title().'”' : '')
            .($content !== '' ? ', with '.str_word_count(strip_tags($content)).' words to begin with' : '')
            .($tags ? ', tagged '.implode(', ', $tags) : '').'.';
        $this->pending = [
            'action' => 'create_draft', 'collection' => $collection, 'title' => $title,
            'content' => $content, 'excerpt' => trim((string) ($args['standfirst'] ?? '')),
            'tags' => $tags, 'parent_id' => $parent?->id(),
            'summary' => $summary, 'button' => 'Yes, start it',
        ];

        return ['status' => 'waiting_for_confirmation', 'summary' => $summary, 'note' => 'Ask the person to confirm with the button under your message.'];
    }

    // Check-ups and briefings ------------------------------------------------

    protected function checkBook(): array
    {
        $entries = Booklet::entries()->all();
        $posts = $entries->filter(fn (Entry $e) => (bool) Booklet::collections()->find($e->collectionHandle())?->dated());
        $published = $posts->filter(fn (Entry $e) => $e->status() === 'published');
        $short = fn ($list) => $list->take(6)->map(fn (Entry $e) => ['id' => $e->id(), 'title' => $e->title() ?: 'Untitled'])->values()->all();

        $stale = $entries->filter(fn (Entry $e) => ! $e->published() && $e->lastModified()
            && Carbon::parse($e->lastModified())->lt(now()->subDays(30)));
        $noStandfirst = $published->filter(fn (Entry $e) => ! trim((string) $e->get('excerpt')));
        $noTags = $published->filter(fn (Entry $e) => ! $e->get('tags'));
        $noMeta = $entries->filter(fn (Entry $e) => $e->status() === 'published' && ! trim((string) $e->get('meta_description')));
        $noAlt = Booklet::assets()->all()->filter(fn ($a) => ! $a->get('alt') && preg_match('/\.(jpe?g|png|gif|webp|avif)$/i', $a->path()));
        $pages = $entries->filter(fn (Entry $e) => $e->collectionHandle() === 'pages' && $e->status() === 'published');
        $notInMenu = $pages->filter(fn (Entry $e) => ! \App\Booklet\Support\Menus::has('header', $e->id()));
        $thisWeek = $entries->filter(fn (Entry $e) => $e->status() === 'scheduled' && $e->date()?->lte(now()->addDays(7)));
        $waiting = Comment::where('status', 'waiting')->count();

        foreach ($stale->merge($noStandfirst)->unique(fn ($e) => $e->id())->take(4) as $e) {
            $this->link($e);
        }
        if ($noAlt->isNotEmpty()) {
            $this->links[] = ['label' => 'Pictures without alt text', 'meta' => $noAlt->count().' to describe', 'href' => route('admin.media'), 'tone' => 'var(--sky)'];
        }
        if ($waiting) {
            $this->links[] = ['label' => 'Comments waiting', 'meta' => $waiting.' to read', 'href' => route('admin.comments'), 'tone' => 'var(--rose)'];
        }

        return [
            'drafts_untouched_30_days' => ['count' => $stale->count(), 'examples' => $short($stale)],
            'published_posts_without_standfirst' => ['count' => $noStandfirst->count(), 'examples' => $short($noStandfirst)],
            'published_posts_without_tags' => ['count' => $noTags->count(), 'examples' => $short($noTags)],
            'published_without_meta_description' => ['count' => $noMeta->count(), 'examples' => $short($noMeta)],
            'pictures_without_alt_text' => ['count' => $noAlt->count(), 'examples' => $noAlt->take(6)->map(fn ($a) => $a->path())->values()->all()],
            'published_pages_not_in_menu' => ['count' => $notInMenu->count(), 'examples' => $short($notInMenu)],
            'scheduled_next_7_days' => $short($thisWeek->sortBy(fn ($e) => $e->attribute('date'))),
            'comments_waiting' => $waiting,
            'broken_links' => ['count' => count($broken = rescue(fn () => \App\Support\LinkCheck::broken(), [], false)), 'examples' => collect($broken)->take(5)->map(fn ($b) => ['in' => $b['title'], 'link' => $b['url'], 'why' => $b['reason']])->all(), 'note' => 'From the weekly link check; fixed or forwarded in Redirects.'],
            'sticky_notes_open' => collect(\App\Models\EntryNote::openCounts())->map(fn ($n, $id) => ['title' => Booklet::entries()->find($id)?->title() ?? 'Untitled', 'notes' => $n])->values()->take(6)->all(),
            'note' => 'Summarise the most useful two or three things to do, kindly, and offer to help with one (for example writing a meta description or tags).',
        ];
    }

    protected function siteHealth(): array
    {
        if (! $this->user->isAdmin()) {
            return ['error' => 'Only admins can see the server check-up. Suggest asking one.'];
        }
        $checks = collect(\App\Support\Health::run());
        $this->links[] = ['label' => 'Check-up', 'meta' => 'Settings', 'href' => route('admin.settings.health'), 'tone' => 'var(--mint)'];

        return [
            'needs_attention' => $checks->whereIn('state', ['fail', 'warn'])->map(fn ($c) => array_filter([
                'what' => $c['label'], 'how_bad' => $c['state'] === 'fail' ? 'needs fixing' : 'worth a look', 'found' => $c['text'], 'fix' => $c['fix'], 'command' => $c['command'],
            ]))->values()->all(),
            'fine' => $checks->where('state', 'ok')->pluck('label')->values()->all(),
            'note' => 'Lead with what needs fixing, in plain words. Commands are for whoever looks after the server.',
        ];
    }

    protected function dailyBrief(): array
    {
        $entries = Booklet::entries()->all();
        $visits = fn ($day) => rescue(fn () => (int) \Illuminate\Support\Facades\DB::table('site_visits')->whereDate('day', $day)->sum('views'), 0, false);
        $today = $entries->filter(fn (Entry $e) => $e->status() === 'scheduled' && $e->date()?->isToday());
        $week = $entries->filter(fn (Entry $e) => $e->status() === 'scheduled' && $e->date()?->lte(now()->addDays(7)));
        $lately = $entries->filter(fn (Entry $e) => ! $e->published() && $e->lastModified() && Carbon::parse($e->lastModified())->gt(now()->subDays(3)))
            ->sortByDesc(fn ($e) => $e->lastModified());

        $lately->take(3)->each(fn ($e) => $this->link($e));

        return [
            'today' => now()->format('l j F'),
            'visits_today' => $visits(today()),
            'visits_yesterday' => $visits(today()->subDay()),
            'comments_waiting' => Comment::where('status', 'waiting')->count(),
            'going_out_today' => $today->map(fn ($e) => $e->title())->values()->all(),
            'scheduled_this_week' => $week->count(),
            'drafts_worked_on_lately' => $lately->take(5)->map(fn ($e) => ['id' => $e->id(), 'title' => $e->title() ?: 'Untitled'])->values()->all(),
            'online_now' => User::all()->filter->isOnline()->pluck('name')->values()->all(),
            // What inserts put on their Contents page (events this week, say).
            'from_inserts' => collect(rescue(fn () => app(\App\Inserts\InsertManager::class)->cards($this->user), [], false))
                ->map(fn ($c) => array_filter(['what' => $c['title'], 'count' => $c['count'], 'note' => $c['text'],
                    'items' => array_map(fn ($i) => trim($i['label'].($i['meta'] !== '' ? ' ('.$i['meta'].')' : '')), $c['items']) ?: null]))
                ->values()->all(),
            'note' => 'Brief them in three or four short sentences, warmly, most useful first.',
        ];
    }

    /**
     * @return array<string, string> slug => title
     */
    protected function cleanTags(array $tags): array
    {
        $out = [];
        foreach (array_slice($tags, 0, 8) as $tag) {
            $title = trim(Str::limit(ltrim(trim((string) $tag), '#'), 40, ''));
            if ($title !== '' && ($slug = Str::slug($title)) !== '') {
                $out[$slug] = Booklet::terms()->findBySlug('tags', $slug)?->title() ?? $title;
            }
        }

        return $out;
    }

    protected function proposeTags(array $args): array
    {
        $entry = Booklet::entries()->find((string) ($args['entry_id'] ?? ''));
        if (! $entry) {
            return ['error' => 'No entry with that id. Use search_content first.'];
        }
        if (! $this->canChange($entry)) {
            return ['error' => 'This person can only change their own drafts.'];
        }

        $tags = $this->cleanTags((array) ($args['tags'] ?? []));
        $category = null;
        if (! empty($args['category'])) {
            $category = Booklet::terms()->findBySlug('categories', Str::slug((string) $args['category']));
            if (! $category) {
                return ['error' => 'There is no category called “'.$args['category'].'”. Existing: '.Booklet::terms()->whereTaxonomy('categories')->map->title()->implode(', ').'.'];
            }
        }
        if (! $tags && ! $category) {
            return ['error' => 'Suggest some tags or a category.'];
        }

        $replace = (bool) ($args['replace'] ?? false);
        $new = array_filter($tags, fn ($slug) => ! Booklet::terms()->findBySlug('tags', $slug), ARRAY_FILTER_USE_KEY);
        $summary = 'For “'.($entry->title() ?: 'Untitled').'”: '
            .($category ? 'file it under '.$category->title() : '')
            .($category && $tags ? ' and ' : '')
            .($tags ? ($replace ? 'set its tags to ' : 'add the tags ').implode(', ', $tags) : '')
            .($new ? ' (new: '.implode(', ', $new).')' : '').'.';

        $this->pending = ['action' => 'tags', 'entry_id' => $entry->id(), 'tags' => $tags, 'category' => $category?->slug(), 'replace' => $replace, 'summary' => $summary, 'button' => 'Yes, tag it'];
        $this->link($entry);

        return ['status' => 'waiting_for_confirmation', 'summary' => $summary, 'note' => 'Ask the person to confirm with the button under your message.'];
    }

    protected function proposeAlt(string $file, string $alt): array
    {
        $asset = Booklet::assets()->all()->first(fn ($a) => $a->path() === ltrim($file, '/'));
        if (! $asset) {
            return ['error' => 'No file at that path. Use find_media first.'];
        }
        $alt = trim(Str::limit($alt, 300, ''));
        if ($alt === '') {
            return ['error' => 'Write the alt text to use.'];
        }

        $summary = 'Describe '.basename($asset->path()).' as “'.$alt.'”.';
        $this->pending = ['action' => 'alt', 'container' => $asset->container(), 'file' => $asset->path(), 'alt' => $alt, 'summary' => $summary, 'button' => 'Yes, save it'];
        $this->links[] = ['label' => basename($asset->path()), 'meta' => 'Open in Media', 'href' => route('admin.media', ['in' => $asset->container(), 'file' => $asset->path()]), 'tone' => 'var(--sky)'];

        return ['status' => 'waiting_for_confirmation', 'summary' => $summary, 'note' => 'Ask the person to confirm with the button under your message.'];
    }

    protected function proposeReply(int $id, string $text): array
    {
        if (! $this->canPublishAny()) {
            return ['error' => 'Only editors and admins reply to comments.'];
        }
        $comment = Comment::find($id);
        if (! $comment || ($comment->parent_id && $comment->user_id)) {
            return ['error' => 'No reader comment with that id. Use list_comments first.'];
        }
        $text = trim(Str::limit($text, 2000, ''));
        if ($text === '') {
            return ['error' => 'Write the reply first.'];
        }

        $summary = 'Reply to '.$comment->author_name.' on “'.($comment->entry()?->title() ?? 'an entry').'” as '.($this->user->byline ?: $this->user->name).': “'.$text.'”'
            .($comment->status === 'waiting' ? ' (their note will be approved too)' : '').'.';
        $this->pending = ['action' => 'reply', 'comment_id' => $id, 'text' => $text, 'summary' => $summary, 'button' => 'Yes, post it'];

        return ['status' => 'waiting_for_confirmation', 'summary' => $summary, 'note' => 'Show the reply and ask the person to confirm with the button under your message.'];
    }

    protected function proposeTranslation(string $id, string $lang): array
    {
        $entry = Booklet::entries()->find($id);
        if (! $entry || ! $this->opens($entry)) {
            return ['error' => 'No entry with that id. Use search_content first.'];
        }
        $lang = strtolower(trim($lang));
        if (! isset(Writer::LANGUAGES[$lang])) {
            return ['error' => 'I can translate into: '.collect(Writer::LANGUAGES)->map(fn ($l) => $l[0])->implode(', ').'.'];
        }
        if ($already = Translations::existing($entry, $lang)) {
            $this->link($already);

            return ['error' => 'There is already a '.Writer::LANGUAGES[$lang][0].' version (“'.($already->title() ?: 'Untitled').'”, id '.$already->id().'). Point them to it instead.'];
        }

        $root = Translations::root($entry);
        $summary = 'Translate “'.($root->title() ?: 'Untitled').'” into '.Writer::LANGUAGES[$lang][0].', as a new draft beside it (same pictures; the original stays as it is).';
        $this->pending = ['action' => 'translate', 'entry_id' => $root->id(), 'lang' => $lang, 'summary' => $summary, 'button' => 'Yes, translate it'];
        $this->link($root);

        return ['status' => 'waiting_for_confirmation', 'summary' => $summary, 'note' => 'Ask the person to confirm with the button under your message. A long post takes a minute to translate.'];
    }

    // Doing it, after "Yes" -------------------------------------------------

    /**
     * Carry out a confirmed proposal. Returns what Stacy says afterwards and
     * a link to what changed.
     *
     * @return array{text: string, link: ?array}
     */
    public static function confirm(array $pending, User $user): array
    {
        $tools = new self($user);

        if (! StacyConfig::allowsAction((string) ($pending['action'] ?? ''))) {
            return ['text' => 'That kind of change has been switched off for me since I offered, so I left things as they are.', 'link' => null];
        }

        if ($pending['action'] === 'insert') {
            return static::confirmSkill($pending, $user);
        }

        if ($pending['action'] === 'comment') {
            $comment = Comment::find((int) ($pending['comment_id'] ?? 0));
            if (! $comment) {
                return ['text' => 'That comment isn’t there any more, so I left things as they are.', 'link' => null];
            }

            $title = $comment->entry()?->title();
            $link = ['label' => 'Comments', 'meta' => 'Open', 'href' => route('admin.comments'), 'tone' => 'var(--rose)'];

            if ($pending['do'] === 'delete') {
                \App\Support\Bin::comment($comment, $user);
                ActivityLog::create(['user_id' => $user->id, 'actor' => 'stacy', 'action' => 'deleted a comment on', 'kind' => 'comments', 'subject_type' => 'comment', 'subject_key' => (string) $comment->id, 'subject_label' => $title, 'properties' => ['for' => $user->name]]);

                return ['text' => 'Done. The note is in the bin, if you change your mind in the next 30 days.', 'link' => ['label' => 'Bin', 'meta' => 'Open', 'href' => route('admin.bin'), 'tone' => 'var(--sand)']];
            }

            $status = $pending['do'] === 'approve' ? 'approved' : 'spam';
            $comment->update(['status' => $status]);
            ActivityLog::create(['user_id' => $user->id, 'actor' => 'stacy', 'action' => $status === 'approved' ? 'approved a comment on' : 'marked a comment as spam on', 'kind' => 'comments', 'subject_type' => 'comment', 'subject_key' => (string) $comment->id, 'subject_label' => $title, 'properties' => ['for' => $user->name]]);

            return ['text' => $status === 'approved' ? 'Done. It’s on the site now.' : 'Done. It’s in the spam pile.', 'link' => $link];
        }

        if ($pending['action'] === 'alt') {
            $asset = Booklet::assets()->find(['container' => (string) $pending['container'], 'path' => (string) $pending['file']]);
            if (! $asset) {
                return ['text' => 'That picture isn’t there any more, so I left things as they are.', 'link' => null];
            }
            $asset->set('alt', (string) $pending['alt']);
            Booklet::assets()->save($asset);
            ActivityLog::create(['user_id' => $user->id, 'actor' => 'stacy', 'action' => 'described a picture', 'kind' => 'uploads', 'subject_type' => 'asset', 'subject_key' => $asset->path(), 'subject_label' => basename($asset->path()), 'properties' => ['for' => $user->name]]);

            return ['text' => 'Done. The picture has its alt text now.', 'link' => ['label' => basename($asset->path()), 'meta' => 'Media', 'href' => route('admin.media', ['in' => $asset->container(), 'file' => $asset->path()]), 'tone' => 'var(--sky)']];
        }

        if ($pending['action'] === 'reply') {
            $comment = Comment::find((int) ($pending['comment_id'] ?? 0));
            if (! $comment || ! in_array($user->level(), ['admin', 'editor'], true)) {
                return ['text' => 'I couldn’t post that reply, so nothing was sent.', 'link' => null];
            }
            $comment->root()->replies()->create([
                'entry_id' => $comment->entry_id,
                'user_id' => $user->id,
                'author_name' => $user->byline ?: $user->name,
                'author_email' => $user->email,
                'body' => (string) $pending['text'],
                'status' => 'approved',
            ]);
            if ($comment->status === 'waiting') {
                $comment->update(['status' => 'approved']);
            }
            ActivityLog::create(['user_id' => $user->id, 'actor' => 'stacy', 'action' => 'replied to a comment on', 'kind' => 'comments', 'subject_type' => 'comment', 'subject_key' => (string) $comment->id, 'subject_label' => $comment->entry()?->title(), 'properties' => ['for' => $user->name]]);

            return ['text' => 'Done. Your reply is on the site.', 'link' => ['label' => 'Comments', 'meta' => 'Open', 'href' => route('admin.comments'), 'tone' => 'var(--rose)']];
        }

        if ($pending['action'] === 'translate') {
            $source = Booklet::entries()->find((string) ($pending['entry_id'] ?? ''));
            if (! $source) {
                return ['text' => 'That entry isn’t in the book any more, so I left things as they are.', 'link' => null];
            }

            try {
                $copy = Translations::make($user, $source, (string) ($pending['lang'] ?? ''));
            } catch (StacyException $e) {
                return ['text' => $e->getMessage(), 'link' => null];
            }

            return ['text' => 'Done. The '.Writer::LANGUAGES[$pending['lang']][0].' draft is ready. Have a read before it goes out; I’m good, but a native speaker is better.', 'link' => static::linkFor($copy)];
        }

        if ($pending['action'] === 'create_draft') {
            $tags = (array) ($pending['tags'] ?? []);
            foreach ($tags as $slug => $tagTitle) {
                if (! Booklet::terms()->findBySlug('tags', (string) $slug)) {
                    Booklet::terms()->make(['taxonomy' => 'tags', 'slug' => (string) $slug], ['title' => $tagTitle])->save();
                }
            }

            $entry = Booklet::entries()->make(
                ['collection' => $pending['collection'], 'published' => false],
                array_filter([
                    'title' => $pending['title'],
                    'excerpt' => ($pending['excerpt'] ?? '') ?: null,
                    'content' => $pending['content'] ?: null,
                    'tags' => $tags ? array_keys($tags) : null,
                    'author' => $user->id,
                ]),
            )->save();

            if (Booklet::collections()->find($pending['collection'])?->structured()) {
                \App\Booklet\Support\PageTree::for($pending['collection'])->place($entry->id(), $pending['parent_id'] ?? null)->save();
            }

            static::stamp($user, 'started a draft', 'edits', $entry);
            Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'started a draft', (string) $user->id, 'Started by Stacy');

            return ['text' => 'Done. The draft is waiting for you.', 'link' => static::linkFor($entry)];
        }

        $entry = Booklet::entries()->find((string) ($pending['entry_id'] ?? ''));

        if (! $entry) {
            return ['text' => 'That entry isn’t in the book any more, so I left things as they are.', 'link' => null];
        }

        $allowed = in_array($pending['action'], ['edit', 'tags'], true) ? $tools->canChange($entry) : $tools->canPublish($entry);
        if (! $allowed) {
            return ['text' => 'You can’t change that one, so I left it alone.', 'link' => null];
        }

        if ($pending['action'] === 'schedule') {
            if (! $entry->collection()?->dated()) {
                return ['text' => 'That one has no date to schedule, so I left it as it is.', 'link' => static::linkFor($entry)];
            }
            $at = Carbon::parse($pending['when'], config('app.timezone'));
            if ($at->isPast()) {
                return ['text' => 'That time has passed while we were talking. Tell me a new time?', 'link' => null];
            }
            $entry->setAttribute('published', true)->setAttribute('date', $at)->save();
            static::stamp($user, 'scheduled', 'publishing', $entry);
            \App\Support\Reviews::settle($entry, $user);
            Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'scheduled', (string) $user->id, 'Scheduled by Stacy');

            return ['text' => 'Done. It’s scheduled for '.$at->format('l j F \a\t g:i a').'. You can change that from the editor.', 'link' => static::linkFor($entry)];
        }

        if ($pending['action'] === 'publish') {
            $entry->setAttribute('published', true);
            if (! $entry->date() || $entry->date()->isFuture()) {
                $entry->setAttribute('date', now());
            }
            $entry->save();
            static::stamp($user, 'published', 'publishing', $entry);
            \App\Support\Reviews::settle($entry, $user);
            app(\App\Inserts\InsertManager::class)->fire('entry.published', $entry);
            Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'published', (string) $user->id, 'Published by Stacy');

            return ['text' => 'Done. It’s live.', 'link' => static::linkFor($entry)];
        }

        if ($pending['action'] === 'unpublish') {
            $entry->setAttribute('published', false)->save();
            static::stamp($user, 'unpublished', 'publishing', $entry);
            Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'unpublished', (string) $user->id, 'Taken off the site by Stacy');

            return ['text' => 'Done. It’s a draft again and off the site.', 'link' => static::linkFor($entry)];
        }

        if ($pending['action'] === 'edit') {
            foreach ((array) ($pending['changes'] ?? []) as $field => $value) {
                if (in_array($field, ['title', 'excerpt', 'meta_title', 'meta_description'], true)) {
                    $entry->set($field, (string) $value);
                }
            }
            $entry->save();
            static::stamp($user, 'edited', 'edits', $entry);
            Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'edited', (string) $user->id, 'Edited by Stacy');

            return ['text' => 'Done. The change is saved, and the old version is kept in History.', 'link' => static::linkFor($entry)];
        }

        if ($pending['action'] === 'tags') {
            $tags = (array) ($pending['tags'] ?? []);
            foreach ($tags as $slug => $title) {
                if (! Booklet::terms()->findBySlug('tags', (string) $slug)) {
                    Booklet::terms()->make(['taxonomy' => 'tags', 'slug' => (string) $slug], ['title' => $title])->save();
                }
            }
            if ($tags) {
                $current = ! empty($pending['replace']) ? [] : (array) $entry->get('tags', []);
                $entry->set('tags', array_values(array_unique(array_merge($current, array_map('strval', array_keys($tags))))));
            }
            if (! empty($pending['category'])) {
                $entry->set('categories', [(string) $pending['category']]);
            }
            $entry->save();
            static::stamp($user, 'tagged', 'edits', $entry);
            Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'edited', (string) $user->id, 'Tagged by Stacy');

            return ['text' => 'Done. It’s tagged.', 'link' => static::linkFor($entry)];
        }

        return ['text' => 'I wasn’t sure what to do with that, so I left things alone.', 'link' => null];
    }

    /** "Yes" to a change an insert offered. */
    protected static function confirmSkill(array $pending, User $user): array
    {
        $manager = app(\App\Inserts\InsertManager::class);
        $found = $manager->skills()[($pending['insert'] ?? '').'__'.($pending['skill'] ?? '')] ?? null;
        if (! $found || ! $found['skill']['changes'] || ! in_array($user->level(), $found['skill']['who'], true)) {
            return ['text' => 'That insert isn’t switched on any more, so I left things as they are.', 'link' => null];
        }
        ['insert' => $insert, 'skill' => $skill] = $found;

        try {
            $out = ($skill['do'])((array) ($pending['data'] ?? []), $insert, $user);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['text' => 'I couldn’t do that: '.implode(' ', \Illuminate\Support\Arr::flatten($e->errors())), 'link' => null];
        } catch (Throwable $e) {
            report($e);
            $manager->noteError($insert->key, $e);

            return ['text' => 'The '.$insert->name().' insert had a problem, so nothing changed. An admin can see what went wrong in Settings › Inserts.', 'link' => null];
        }

        $out = is_array($out) ? $out : ['text' => is_string($out) && $out !== '' ? $out : 'Done.'];
        $link = null;
        if (is_array($out['link'] ?? null) && ($href = static::safeHref($out['link']['href'] ?? null))) {
            $link = ['label' => Str::limit((string) ($out['link']['label'] ?? $insert->name()), 80), 'meta' => Str::limit((string) ($out['link']['meta'] ?? 'Open'), 40), 'href' => $href, 'tone' => 'var(--'.$insert->sticker().')'];
        } elseif ($insert->canOpen($user)) {
            $link = ['label' => $insert->page()['title'], 'meta' => 'Open', 'href' => $insert->pageUrl(), 'tone' => 'var(--'.$insert->sticker().')'];
        }
        ActivityLog::create(['user_id' => $user->id, 'actor' => 'stacy', 'action' => 'used the insert', 'kind' => 'settings', 'subject_type' => 'insert', 'subject_key' => $insert->key,
            'subject_label' => Str::limit($insert->name().': '.($pending['summary'] ?? ''), 180), 'properties' => ['for' => $user->name]]);

        return ['text' => (string) ($out['text'] ?? 'Done.'), 'link' => $link];
    }

    protected static function linkFor(Entry $entry): array
    {
        return [
            'label' => $entry->title() ?: 'Untitled',
            'meta' => ucfirst($entry->status()),
            'href' => route('admin.entries.edit', [$entry->collectionHandle(), $entry->id()]),
            'tone' => ['draft' => 'var(--sun)', 'scheduled' => 'var(--sky)', 'published' => 'var(--mint)'][$entry->status()],
        ];
    }

    protected static function stamp(User $user, string $action, string $kind, Entry $entry): void
    {
        ActivityLog::create([
            'user_id' => $user->id,
            'actor' => 'stacy',
            'action' => $action,
            'kind' => $kind,
            'subject_type' => 'entry',
            'subject_key' => $entry->id(),
            'subject_label' => $entry->title(),
            'properties' => ['for' => $user->name],
        ]);
    }
}
