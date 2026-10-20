<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Support\AssetUploader;
use App\Booklet\Support\PageTree;
use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\Redirect;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;

/**
 * Moving in from WordPress: its export file (Tools › Export › All content)
 * becomes posts, pages, categories, tags, comments and pictures here, with
 * redirects from the old addresses so links people shared keep working.
 *
 * Safe to run twice: anything already brought in (remembered by its
 * WordPress id) is skipped. Nothing on the WordPress site is touched.
 */
class WordPressImport
{
    public const DIR = 'imports';

    public const MAX_BYTES = 200 * 1024 * 1024;

    /** Post types brought in, and where they go. */
    protected const TYPES = ['post' => 'posts', 'page' => 'pages'];

    /** Shortcodes that only wrap their content; the content stays. */
    protected const WRAPPERS = ['caption', 'wp_caption', 'su_note', 'su_box', 'su_quote', 'su_highlight', 'vc_row', 'vc_column', 'vc_column_text', 'et_pb_section', 'et_pb_row', 'et_pb_column', 'et_pb_text', 'fusion_builder_container', 'fusion_builder_row', 'fusion_builder_column', 'fusion_text'];

    /** Shortcodes that can't mean anything here; they're left out (and counted). */
    protected const DROPPED = ['gallery', 'playlist', 'contact-form-7', 'contact-form', 'wpforms', 'gravityform', 'mc4wp_form', 'instagram-feed', 'jetpack_subscription_form', 'products', 'product', 'add_to_cart'];

    protected array $report = [];

    protected array $notes = [];

    public function __construct(protected array $options = []) {}

    // The file -------------------------------------------------------------------

    public static function disk(): \Illuminate\Filesystem\FilesystemAdapter
    {
        return \App\Support\Disk::of('local');
    }

    /** Keep an uploaded export for a look inside. Returns its name. */
    public static function keepUpload(string $tmp): string
    {
        if (filesize($tmp) > self::MAX_BYTES) {
            throw new RuntimeException('That file is bigger than '.(self::MAX_BYTES / 1048576).' MB. Export one part at a time (WordPress lets you choose posts or pages, and dates).');
        }
        $head = (string) file_get_contents($tmp, false, null, 0, 4096);
        if (! str_contains($head, 'wordpress.org/export/')) {
            throw new RuntimeException('That doesn’t look like a WordPress export. In WordPress, go to Tools › Export, choose “All content”, and upload the .xml file it gives you.');
        }

        $name = 'wordpress-'.now()->format('Y-m-d-His').'-'.Str::lower(Str::random(5)).'.xml';
        self::disk()->putFileAs(self::DIR, new \Illuminate\Http\File($tmp), $name);

        return $name;
    }

    public static function path(string $name): string
    {
        $name = basename($name);
        if (! preg_match('/^wordpress-[\w.-]+\.xml$/', $name) || ! self::disk()->exists(self::DIR.'/'.$name)) {
            throw new RuntimeException('That export isn’t here any more. Upload it again.');
        }

        return self::disk()->path(self::DIR.'/'.$name);
    }

    public static function forget(string $name): void
    {
        rescue(fn () => self::disk()->delete(self::DIR.'/'.basename($name)), null, false);
    }

    /**
     * Read the export: the site, its people, categories, tags and items.
     *
     * @return array{site: array, authors: array, categories: array, tags: array, items: array}
     */
    public static function read(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if (preg_match('/<!DOCTYPE/i', substr($raw, 0, 2048))) {
            throw new RuntimeException('That file has things in it a WordPress export never has, so it wasn’t read.');
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_PARSEHUGE | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $xml || ! isset($xml->channel)) {
            throw new RuntimeException('The file couldn’t be read. It may have been cut short; export it again.');
        }

        $ns = $xml->getDocNamespaces(true);
        // "wp" is http://wordpress.org/export/1.x/ (not …/excerpt/, which starts the same way).
        $wpNs = $ns['wp'] ?? collect($ns)->first(fn ($uri) => (bool) preg_match('#^https?://wordpress\.org/export/[\d.]+/?$#', (string) $uri)) ?? 'http://wordpress.org/export/1.2/';
        $contentNs = $ns['content'] ?? 'http://purl.org/rss/1.0/modules/content/';
        $excerptNs = $ns['excerpt'] ?? 'http://wordpress.org/export/1.2/excerpt/';
        $dcNs = $ns['dc'] ?? 'http://purl.org/dc/elements/1.1/';

        $channel = $xml->channel;
        $wp = $channel->children($wpNs);
        $text = fn ($v) => trim((string) $v);

        $site = [
            'title' => html_entity_decode($text($channel->title), ENT_QUOTES | ENT_HTML5),
            'link' => $text($channel->link),
            'base' => rtrim($text($wp->base_site_url) ?: $text($channel->link), '/'),
            'blog' => rtrim($text($wp->base_blog_url) ?: $text($channel->link), '/'),
        ];

        $authors = [];
        foreach ($wp->author as $a) {
            $login = $text($a->author_login);
            $authors[$login] = [
                'login' => $login,
                'email' => Str::lower($text($a->author_email)),
                'name' => html_entity_decode($text($a->author_display_name) ?: $login, ENT_QUOTES | ENT_HTML5),
            ];
        }

        $categories = [];
        foreach ($wp->category as $c) {
            $slug = urldecode($text($c->category_nicename));
            $categories[$slug] = html_entity_decode($text($c->cat_name), ENT_QUOTES | ENT_HTML5);
        }
        $tags = [];
        foreach ($wp->tag as $t) {
            $slug = urldecode($text($t->tag_slug));
            $tags[$slug] = html_entity_decode($text($t->tag_name), ENT_QUOTES | ENT_HTML5);
        }

        $items = [];
        foreach ($channel->item as $item) {
            $w = $item->children($wpNs);
            $meta = [];
            foreach ($w->postmeta as $m) {
                $meta[$text($m->meta_key)] = (string) $m->meta_value;
            }
            $cats = [];
            $itemTags = [];
            foreach ($item->category as $c) {
                $domain = (string) $c['domain'];
                $slug = urldecode((string) $c['nicename']);
                $name = html_entity_decode($text($c), ENT_QUOTES | ENT_HTML5);
                if ($domain === 'category') {
                    $cats[$slug] = $name;
                    $categories[$slug] ??= $name;
                } elseif ($domain === 'post_tag') {
                    $itemTags[$slug] = $name;
                    $tags[$slug] ??= $name;
                }
            }
            $comments = [];
            foreach ($w->comment as $c) {
                $comments[] = [
                    'id' => (int) $c->comment_id,
                    'author' => html_entity_decode($text($c->comment_author), ENT_QUOTES | ENT_HTML5),
                    'email' => Str::lower($text($c->comment_author_email)),
                    'date' => $text($c->comment_date),
                    'content' => (string) $c->comment_content,
                    'approved' => $text($c->comment_approved),
                    'type' => $text($c->comment_type),
                    'parent' => (int) $c->comment_parent,
                    'user' => (int) $c->comment_user_id,
                ];
            }

            $items[] = [
                'id' => (int) $w->post_id,
                'type' => $text($w->post_type),
                'title' => html_entity_decode($text($item->title), ENT_QUOTES | ENT_HTML5),
                'link' => $text($item->link),
                'creator' => $text($item->children($dcNs)->creator),
                'content' => (string) $item->children($contentNs)->encoded,
                'excerpt' => (string) $item->children($excerptNs)->encoded,
                'date' => $text($w->post_date),
                'date_gmt' => $text($w->post_date_gmt),
                'slug' => urldecode($text($w->post_name)),
                'status' => $text($w->status),
                'parent' => (int) $w->post_parent,
                'order' => (int) $w->menu_order,
                'password' => $text($w->post_password),
                'attachment_url' => $text($w->attachment_url),
                'categories' => $cats,
                'tags' => $itemTags,
                'meta' => $meta,
                'comments' => $comments,
            ];
        }

        return compact('site', 'authors', 'categories', 'tags', 'items');
    }

    /** What's inside, for the look before bringing it in. */
    public static function summary(string $name): array
    {
        $data = self::read(self::path($name));
        $items = collect($data['items']);
        $content = $items->filter(fn ($i) => isset(self::TYPES[$i['type']]) && ! in_array($i['status'], ['trash', 'auto-draft', 'inherit'], true));
        $imported = self::imported();

        return [
            'name' => $name,
            'site' => $data['site'],
            // Counted as they'll arrive: password-protected ones come in as drafts.
            'posts' => $content->where('type', 'post')->where('status', 'publish')->where('password', '')->count(),
            'pages' => $content->where('type', 'page')->where('status', 'publish')->where('password', '')->count(),
            'scheduled' => $content->where('status', 'future')->where('password', '')->count(),
            'drafts' => $content->filter(fn ($i) => in_array($i['status'], ['draft', 'pending', 'private'], true) || $i['password'] !== '')->count(),
            'protected' => $content->filter(fn ($i) => $i['password'] !== '')->count(),
            'pictures' => $items->where('type', 'attachment')->count(),
            'comments' => $content->sum(fn ($i) => collect($i['comments'])->filter(fn ($c) => $c['approved'] === '1' && ! in_array($c['type'], ['pingback', 'trackback'], true))->count()),
            'categories' => count($data['categories']),
            'tags' => count($data['tags']),
            'authors' => array_values($data['authors']),
            'other' => $items->reject(fn ($i) => isset(self::TYPES[$i['type']]) || in_array($i['type'], ['attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'custom_css', 'wp_global_styles', 'oembed_cache'], true))->countBy('type')->all(),
            'already' => $content->filter(fn ($i) => self::existing($data['site']['base'], $i['id'], $imported) !== null)->count(),
        ];
    }

    // Bringing it in ------------------------------------------------------------

    /**
     * Options: pictures (bool), drafts (bool), comments (bool), redirects (bool),
     * authors ('match' | 'me' | 'create').
     */
    public static function run(string $name, User $by, array $options = []): array
    {
        $import = new self($options + ['pictures' => true, 'drafts' => true, 'comments' => true, 'redirects' => true, 'authors' => 'match']);

        return $import->bringIn(self::read(self::path($name)), $by);
    }

    public function bringIn(array $data, User $by): array
    {
        @set_time_limit(0);
        $this->report = ['posts' => 0, 'pages' => 0, 'drafts' => 0, 'comments' => 0, 'pictures' => 0, 'categories' => 0, 'tags' => 0, 'redirects' => 0, 'people' => 0, 'skipped' => 0, 'already' => 0];
        $this->notes = [];
        $base = $data['site']['base'];

        $people = $this->people($data['authors'], $by);
        $this->terms('categories', $data['categories']);
        $this->terms('tags', $data['tags']);

        $items = collect($data['items']);
        $attachments = $items->where('type', 'attachment')->keyBy('id');
        $pictures = $this->options['pictures'] ? $this->pictures($attachments, $base) : ['byId' => [], 'byUrl' => []];

        $made = []; // wp id => Entry
        $new = []; // wp id => true, for entries made in this run
        $pages = [];
        $imported = self::imported();

        foreach ($items as $item) {
            $collection = self::TYPES[$item['type']] ?? null;
            if (! $collection) {
                continue;
            }
            if (in_array($item['status'], ['trash', 'auto-draft', 'inherit'], true)) {
                continue;
            }
            $collection = $this->collectionFor($collection);
            if (! $collection) {
                $this->note('There’s no chapter for '.$item['type'].'s here, so “'.$item['title'].'” was left out.');
                $this->report['skipped']++;

                continue;
            }
            if ($existing = self::existing($base, $item['id'], $imported)) {
                $made[$item['id']] = $existing;
                $this->report['already']++;

                continue;
            }

            $live = in_array($item['status'], ['publish', 'future'], true) && $item['password'] === '';
            if (! $live && ! $this->options['drafts']) {
                $this->report['skipped']++;

                continue;
            }
            if ($item['password'] !== '') {
                $this->note('“'.$item['title'].'” was password-protected on WordPress, so it came in as a draft.');
            }

            $entry = $this->entry($item, $collection, $live, $people, $by, $pictures, $base);
            $made[$item['id']] = $entry;
            $new[$item['id']] = true;

            if (Booklet::collections()->find($collection)?->structured()) {
                $pages[] = ['item' => $item, 'entry' => $entry, 'live' => $live];
            }

            $this->report[$live ? ($item['type'] === 'page' ? 'pages' : 'posts') : 'drafts']++;
        }

        $this->tree($pages, $made);
        $this->links($made, $items->keyBy('id'), $base);

        if ($this->options['comments']) {
            foreach ($items as $item) {
                // Only for what came in this time: a second run doesn't bring the same comments again.
                if (isset($made[$item['id']], $new[$item['id']]) && $item['comments']) {
                    $this->comments($item['comments'], $made[$item['id']], $people);
                }
            }
        }

        if ($this->options['redirects']) {
            $this->redirects($made, $items->keyBy('id'), $by);
        }

        ActivityLog::create([
            'user_id' => $by->id,
            'action' => 'brought in from WordPress',
            'kind' => 'edits',
            'subject_type' => 'import',
            'subject_key' => $base,
            'subject_label' => $data['site']['title'] ?: $base,
            'properties' => $this->report,
        ]);

        return ['report' => $this->report, 'notes' => array_slice($this->notes, 0, 60), 'more_notes' => max(0, count($this->notes) - 60)];
    }

    /**
     * Entries already brought in from WordPress, by their WordPress id.
     *
     * @return \Illuminate\Support\Collection<string, Entry>
     */
    public static function imported(): \Illuminate\Support\Collection
    {
        return Booklet::entries()->all()
            ->filter(fn (Entry $e) => $e->get('wp_id'))
            ->keyBy(fn (Entry $e) => (string) $e->get('wp_id'));
    }

    /** The entry already brought in from this WordPress post, if any. */
    public static function existing(string $base, int $id, ?\Illuminate\Support\Collection $imported = null): ?Entry
    {
        return ($imported ?? self::imported())->get(self::wpId($base, $id));
    }

    protected static function wpId(string $base, int $id): string
    {
        return Str::lower(preg_replace('#^https?://(www\.)?#i', '', $base)).'#'.$id;
    }

    protected function collectionFor(string $handle): ?string
    {
        if (Booklet::collections()->find($handle)) {
            return $handle;
        }
        $all = Booklet::collections()->all();
        $match = $handle === 'posts' ? $all->first(fn ($c) => $c->dated()) : $all->first(fn ($c) => $c->structured());

        return $match?->handle();
    }

    /**
     * Who wrote what: matched by email to people here, or all yours, or new writers.
     *
     * @return array<string, int> login => user id
     */
    protected function people(array $authors, User $by): array
    {
        $map = [];
        foreach ($authors as $login => $author) {
            $user = null;
            if ($this->options['authors'] !== 'me' && $author['email'] !== '') {
                $user = User::whereRaw('lower(email) = ?', [$author['email']])->first();
            }
            if (! $user && $this->options['authors'] === 'create' && filter_var($author['email'], FILTER_VALIDATE_EMAIL)) {
                $user = User::create([
                    'name' => Str::limit($author['name'], 120, ''),
                    'email' => $author['email'],
                    'role' => 'writer',
                    'password' => Str::password(32), // they set their own with "Forgot password?"
                ]);
                $this->report['people']++;
                $this->note($author['name'].' was added as a writer. They can sign in after choosing a password with “Forgot password?”.');
            }
            $map[$login] = $user?->id ?? $by->id;
        }

        return $map;
    }

    protected function terms(string $taxonomy, array $terms): void
    {
        if (! Booklet::taxonomies()->find($taxonomy)) {
            return;
        }
        foreach ($terms as $slug => $title) {
            $slug = Str::slug((string) $slug) ?: Str::slug($title);
            if ($slug === '' || $slug === 'uncategorized' || Booklet::terms()->findBySlug($taxonomy, $slug)) {
                continue;
            }
            Booklet::terms()->make(['taxonomy' => $taxonomy, 'slug' => $slug], ['title' => Str::limit($title, 120, '')])->save();
            $this->report[$taxonomy]++;
        }
    }

    /**
     * Download the pictures (and other files Media takes) into Media › wordpress.
     *
     * @return array{byId: array<int, string>, byUrl: array<string, string>}
     */
    protected function pictures($attachments, string $base): array
    {
        $byId = [];
        $byUrl = [];
        $allowed = explode(',', AssetUploader::MIMES);
        $uploader = new AssetUploader('media');

        foreach ($attachments as $id => $a) {
            $url = $a['attachment_url'];
            $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            if ($url === '' || ! in_array($extension, $allowed, true)) {
                continue;
            }

            $why = null;
            $tmp = SafeFetch::download($url, $why);
            if (! $tmp) {
                $this->note('Couldn’t bring over '.basename((string) parse_url($url, PHP_URL_PATH)).': '.$why.'. The post links to the old address instead.');

                continue;
            }

            // wp-content/uploads/2023/05/cat.jpg → Media › wordpress/2023/05
            $relative = Str::after((string) parse_url($url, PHP_URL_PATH), '/uploads/');
            $folder = 'wordpress'.(preg_match('#^(\d{4}/\d{2})/#', $relative, $m) ? '/'.$m[1] : '');

            try {
                $asset = $uploader->store(new UploadedFile($tmp, basename((string) parse_url($url, PHP_URL_PATH)), null, null, true), $folder);
            } catch (\Throwable $e) {
                $this->note('Couldn’t keep '.basename($url).': '.$e->getMessage());
                @unlink($tmp);

                continue;
            }
            @unlink($tmp);

            $alt = trim((string) ($a['meta']['_wp_attachment_image_alt'] ?? ''));
            if ($alt !== '' || $a['excerpt'] !== '') {
                $asset->set('alt', Str::limit($alt !== '' ? $alt : strip_tags($a['excerpt']), 300, ''));
                Booklet::assets()->save($asset);
            }

            $byId[$id] = $asset->path();
            $byUrl[self::stripSize($url)] = $asset->path();
            $this->report['pictures']++;
        }

        return ['byId' => $byId, 'byUrl' => $byUrl];
    }

    /** cat-300x200.jpg and cat-scaled.jpg are both cat.jpg. */
    protected static function stripSize(string $url): string
    {
        $url = preg_replace('#^https?://(www\.)?#i', '', strtok($url, '?'));

        return Str::lower((string) preg_replace('/-(\d+x\d+|scaled|rotated|e\d{10,})(?=\.\w+$)/i', '', $url));
    }

    protected function entry(array $item, string $collection, bool $live, array $people, User $by, array $pictures, string $base): Entry
    {
        $model = Booklet::collections()->find($collection);
        $content = $this->content($item['content'], $pictures);

        $categories = array_keys(array_filter($item['categories'], fn ($name, $slug) => Str::slug((string) $slug) !== 'uncategorized', ARRAY_FILTER_USE_BOTH));
        $categories = array_values(array_filter(array_map(fn ($slug) => Str::slug((string) $slug), $categories)));
        $tags = array_values(array_unique(array_filter(array_merge(
            array_map(fn ($slug) => Str::slug((string) $slug), array_keys($item['tags'])),
            array_slice($categories, 1), // a post here has one category; any others become tags
        ))));
        foreach (array_slice($categories, 1) as $slug) {
            if (! Booklet::terms()->findBySlug('tags', $slug) && Booklet::taxonomies()->find('tags')) {
                Booklet::terms()->make(['taxonomy' => 'tags', 'slug' => $slug], ['title' => $item['categories'][$slug] ?? Str::headline($slug)])->save();
            }
        }

        $metaTitle = $item['meta']['_yoast_wpseo_title'] ?? $item['meta']['rank_math_title'] ?? $item['meta']['_aioseo_title'] ?? '';
        $metaDescription = $item['meta']['_yoast_wpseo_metadesc'] ?? $item['meta']['rank_math_description'] ?? $item['meta']['_aioseo_description'] ?? '';
        // SEO plugins' placeholders (%%title%%, %sep%…) mean nothing here.
        $clean = fn ($v) => preg_match('/%%?[a-z_]+%%?/i', (string) $v) ? '' : trim(strip_tags((string) $v));

        $thumbnail = (int) ($item['meta']['_thumbnail_id'] ?? 0);
        $date = $item['date'] && $item['date'] !== '0000-00-00 00:00:00'
            ? Carbon::parse($item['date'], config('app.timezone'))
            : ($item['date_gmt'] && $item['date_gmt'] !== '0000-00-00 00:00:00' ? Carbon::parse($item['date_gmt'], 'UTC')->setTimezone(config('app.timezone')) : null);

        $data = array_filter([
            'title' => Str::limit($item['title'] ?: 'Untitled', 200, ''),
            'content' => $content,
            'excerpt' => Str::limit(trim(html_entity_decode(strip_tags($item['excerpt']), ENT_QUOTES | ENT_HTML5)), 400, ''),
            'author' => $people[$item['creator']] ?? $by->id,
            'categories' => $categories ? [$categories[0]] : null,
            'tags' => $tags ?: null,
            'cover' => isset($pictures['byId'][$thumbnail]) ? [$pictures['byId'][$thumbnail]] : null,
            'meta_title' => Str::limit($clean($metaTitle), 70, ''),
            'meta_description' => Str::limit($clean($metaDescription), 170, ''),
            'wp_id' => self::wpId($base, $item['id']),
            'no_email' => true, // old news: never mailed out to subscribers
            'wp_link' => $item['link'],
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        $structured = (bool) $model?->structured();
        $entry = Booklet::entries()->make(
            // Pages go live after they're in the tree (their address depends on their parent).
            ['collection' => $collection, 'published' => $live && ! $structured],
            $data,
        );
        $entry->setAttribute('slug', Str::slug($item['slug'] ?: $item['title']) ?: null);
        if ($model?->dated()) {
            $entry->setAttribute('date', $date ?? now());
        }
        $entry->save();

        Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'started a draft', (string) $by->id, 'Brought in from WordPress');

        return $entry;
    }

    /** WordPress's text, as Markdown, with pictures pointing at Media. */
    protected function content(string $html, array $pictures): string
    {
        // [caption]<img …> Words[/caption] → a figure with a caption
        $html = preg_replace_callback('#\[caption[^\]]*\](.*?)\[/caption\]#is', function ($m) {
            if (preg_match('#^(.*?<img[^>]*>(?:\s*</a>)?)(.*)$#is', trim($m[1]), $parts)) {
                return '<figure>'.$parts[1].'<figcaption>'.trim($parts[2]).'</figcaption></figure>';
            }

            return $m[1];
        }, $html);
        // [embed]https://…[/embed] and bare video links on their own line
        $html = preg_replace('#\[embed[^\]]*\](.*?)\[/embed\]#is', '<p><a href="$1">$1</a></p>', (string) $html);
        $html = preg_replace('#^\s*(https?://(?:www\.)?(?:youtube\.com|youtu\.be|vimeo\.com)/\S+)\s*$#mi', '<p><a href="$1">Watch the video</a></p>', (string) $html);

        foreach (self::DROPPED as $code) {
            $html = preg_replace_callback('#\['.preg_quote($code, '#').'\b[^\]]*\](?:.*?\[/'.preg_quote($code, '#').'\])?#is', function () use ($code) {
                $this->note('A ['.$code.'] block was left out (it only works on WordPress).');

                return '';
            }, (string) $html);
        }
        foreach (self::WRAPPERS as $code) {
            $html = preg_replace('#\[/?'.preg_quote($code, '#').'\b[^\]]*\]#i', '', (string) $html);
        }

        // Pictures and links to files that came across point at their new home.
        if ($pictures['byUrl']) {
            $html = preg_replace_callback('#(?:https?:)?//[^\s"\'<>()]+?/wp-content/uploads/[^\s"\'<>()]+#i', function ($m) use ($pictures) {
                $path = $pictures['byUrl'][self::stripSize(str_starts_with($m[0], '//') ? 'https:'.$m[0] : $m[0])] ?? null;

                return $path ? \App\Support\Disk::of('public')->url($path) : $m[0];
            }, (string) $html);
        }

        return HtmlToMarkdown::convert((string) $html);
    }

    /** Pages in their places (in WordPress's order), then live. */
    protected function tree(array $pages, array $made): void
    {
        if ($pages === []) {
            return;
        }

        $byCollection = collect($pages)->groupBy(fn ($p) => $p['entry']->collectionHandle());
        foreach ($byCollection as $collection => $group) {
            $tree = PageTree::for($collection);
            $depth = function ($item) use (&$depth, $group) {
                $parent = $group->first(fn ($p) => $p['item']['id'] === $item['parent']);

                return $parent ? 1 + $depth($parent['item']) : 0;
            };
            foreach ($group->sortBy(fn ($p) => [$depth($p['item']), $p['item']['order'], $p['item']['title']]) as $page) {
                $parent = $made[$page['item']['parent']] ?? null;
                $tree->place($page['entry']->id(), $parent?->id());
            }
            $tree->save();

            foreach ($group->sortBy(fn ($p) => $depth($p['item'])) as $page) {
                $fresh = Booklet::entries()->find($page['entry']->id());
                if ($fresh && $page['live']) {
                    $fresh->setAttribute('published', true)->save();
                }
            }
        }
    }

    /** Links between the old posts point at their new addresses. */
    protected function links(array $made, $items, string $base): void
    {
        $map = [];
        foreach ($made as $wpId => $entry) {
            $fresh = Booklet::entries()->find($entry->id());
            if (! $fresh || ! $fresh->uri()) {
                continue;
            }
            $old = $items[$wpId]['link'] ?? '';
            if ($old !== '') {
                $map[rtrim(preg_replace('#^https?://(www\.)?#i', '', $old), '/')] = $fresh->uri();
            }
            $host = preg_replace('#^https?://(www\.)?#i', '', $base);
            $map[$host.'/?p='.$wpId] = $fresh->uri();
            $map[$host.'/?page_id='.$wpId] = $fresh->uri();
        }
        if ($map === []) {
            return;
        }

        foreach ($made as $entry) {
            $fresh = Booklet::entries()->find($entry->id());
            $content = (string) $fresh?->get('content', '');
            if ($content === '' || ! str_contains($content, '](')) {
                continue;
            }
            $changed = preg_replace_callback('#\]\(<?((?:https?:)?//(?:www\.)?([^\s)>]+?))/?>?\)#i', function ($m) use ($map) {
                $key = rtrim(preg_replace('#^(https?:)?//(www\.)?#i', '', $m[1]), '/');

                return isset($map[$key]) ? ']('.$map[$key].')' : $m[0];
            }, $content);
            if ($changed !== $content) {
                $fresh->set('content', $changed)->save();
            }
        }
    }

    protected function comments(array $comments, Entry $entry, array $people): void
    {
        $ids = [];
        $parents = [];
        foreach ($comments as $c) {
            $parents[$c['id']] = $c['parent'];
        }
        $top = function (int $id) use (&$top, $parents) {
            $parent = $parents[$id] ?? 0;

            return $parent && isset($parents[$parent]) ? $top($parent) : $id;
        };

        foreach (collect($comments)->sortBy('date') as $c) {
            if (in_array($c['type'], ['pingback', 'trackback'], true) || ! in_array($c['approved'], ['1', '0'], true) || trim(strip_tags($c['content'])) === '') {
                continue;
            }
            $root = $c['parent'] ? $top($c['id']) : null;
            $comment = new Comment([
                'entry_id' => $entry->id(),
                'parent_id' => $root && isset($ids[$root]) ? $ids[$root] : null,
                'author_name' => Str::limit($c['author'] ?: 'A reader', 80, ''),
                'author_email' => filter_var($c['email'], FILTER_VALIDATE_EMAIL) ? $c['email'] : null,
                'body' => Str::limit(trim(html_entity_decode(strip_tags($c['content']), ENT_QUOTES | ENT_HTML5)), 5000, ''),
                'status' => $c['approved'] === '1' ? 'approved' : 'waiting',
            ]);
            $when = $c['date'] ? rescue(fn () => Carbon::parse($c['date'], config('app.timezone')), now(), false) : now();
            $comment->created_at = $when;
            $comment->updated_at = $when;
            $comment->save();
            $ids[$c['id']] = $comment->id;
            $this->report['comments']++;
        }
    }

    /** The old addresses forward to the new ones. */
    protected function redirects(array $made, $items, User $by): void
    {
        foreach ($made as $wpId => $entry) {
            $fresh = Booklet::entries()->find($entry->id());
            $old = $items[$wpId]['link'] ?? '';
            if (! $fresh || $fresh->status() !== 'published' || ! $fresh->uri() || $old === '' || parse_url($old, PHP_URL_QUERY)) {
                continue;
            }
            $from = Redirects::normalise($old);
            if ($from === '/' || $from === $fresh->uri()) {
                continue;
            }
            if ($here = Booklet::entries()->all()->first(fn (Entry $e) => $e->uri() === $from && $e->status() === 'published' && $e->id() !== $fresh->id())) {
                $this->note('“'.$fresh->title().'” is at '.$fresh->uri().', because “'.($here->title() ?: 'a page').'” already uses '.$from.'. If that one was only a placeholder, delete it and change the address in the editor.');

                continue;
            }
            Redirect::updateOrCreate(['from_path' => $from], [
                'to_path' => $fresh->uri(),
                'status' => 301,
                'entry_id' => $fresh->id(),
                'automatic' => true,
                'created_by' => $by->id,
            ]);
            $this->report['redirects']++;
        }
    }

    protected function note(string $text): void
    {
        if (! in_array($text, $this->notes, true)) {
            $this->notes[] = $text;
        }
    }
}
