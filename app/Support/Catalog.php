<?php

namespace App\Support;

use App\Inserts\InsertManager;
use App\Inserts\ManifestReader;
use App\Themes\ThemeManager;
use App\Themes\ThemeManifest;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finding themes and inserts on GitHub, from inside Booklet: repositories
 * with the topic booklet-theme or booklet-insert (see Sharing), newest or
 * most starred first, each described from its own theme.json or
 * insert.php (read as text; nothing runs). Results are kept for a while,
 * as GitHub only answers so many searches an hour without a token.
 *
 * Nothing found here is reviewed by anyone: adding one goes through the
 * same "look inside first" as a link, and code asks for a password.
 */
class Catalog
{
    public const PER_PAGE = 12;

    public const SORTS = ['stars' => 'Most stars', 'updated' => 'Recently updated'];

    /** How long a search and a repository's details are kept. */
    protected const KEEP_MINUTES = 30;

    /**
     * @return array{items: list<array>, total: int, page: int, more: bool, error: ?string, at: string}
     */
    public static function search(string $kind, string $words = '', string $sort = 'stars', int $page = 1): array
    {
        $kind = $kind === 'insert' ? 'insert' : 'theme';
        $words = trim((string) preg_replace('/[^\p{L}\p{N} _.-]+/u', ' ', Str::limit($words, 60, '')));
        $sort = array_key_exists($sort, self::SORTS) ? $sort : 'stars';
        $page = max(1, min(20, $page));
        $key = 'booklet.catalog.'.md5(implode('|', [$kind, mb_strtolower($words), $sort, $page]));

        if (is_array($kept = Cache::get($key))) {
            return $kept;
        }

        $query = trim('topic:'.Sharing::TOPICS[$kind].' archived:false '.$words);
        try {
            $response = Http::withHeaders(self::headers())->timeout(12)->connectTimeout(6)
                ->get('https://api.github.com/search/repositories', ['q' => $query, 'sort' => $sort, 'order' => 'desc', 'per_page' => self::PER_PAGE, 'page' => $page]);
        } catch (Throwable) {
            return self::failed('GitHub didn’t answer. Check the server can reach github.com, then try again.', $key);
        }
        if (in_array($response->status(), [403, 429], true)) {
            $reset = (int) $response->header('X-RateLimit-Reset');
            $when = $reset > 0 ? ' Try again '.Carbon::createFromTimestamp($reset)->diffForHumans().'.' : ' Try again in a while.';

            return self::failed('GitHub has had enough searches from this server for now.'.$when.(config('booklet.github_token') ? '' : ' A GitHub token (BOOKLET_GITHUB_TOKEN in .env) allows many more.'), $key);
        }
        if (! $response->successful()) {
            return self::failed('GitHub said '.$response->status().'. Try again in a minute.', $key);
        }

        $repos = collect((array) $response->json('items', []))
            ->filter(fn ($r) => is_array($r) && preg_match('#^[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}$#', (string) ($r['full_name'] ?? '')))
            ->values();
        $details = self::details($kind, $repos->all());

        $items = $repos->map(function (array $r) use ($details) {
            $full = (string) $r['full_name'];
            $d = $details[$full] ?? [];
            $key = (string) ($d['key'] ?? preg_replace(['/^booklet-(theme-|insert-)?/', '/[^a-z0-9-]/'], '', strtolower((string) $r['name'])));

            return [
                'repo' => $full,
                'key' => $key,
                'name' => (string) ($d['name'] ?? Str::headline(preg_replace('/^booklet-/', '', (string) $r['name']))),
                'about' => Str::limit(strip_tags((string) ($d['about'] ?? $r['description'] ?? '')), 220),
                'version' => $d['version'] ?? null,
                'author' => $d['author'] ?? (string) ($r['owner']['login'] ?? ''),
                'needs' => $d['needs'] ?? null,
                'scripts' => (bool) ($d['scripts'] ?? false),
                'preview' => $d['preview'] ?? null,
                'readable' => $d !== [],
                'stars' => (int) ($r['stargazers_count'] ?? 0),
                'updated' => (string) ($r['pushed_at'] ?? $r['updated_at'] ?? ''),
                'url' => 'https://github.com/'.$full,
                'license' => is_string($r['license']['spdx_id'] ?? null) && $r['license']['spdx_id'] !== 'NOASSERTION' ? $r['license']['spdx_id'] : null,
            ];
        })->all();

        $total = (int) $response->json('total_count', 0);
        $result = ['items' => $items, 'total' => $total, 'page' => $page, 'more' => $page * self::PER_PAGE < min($total, 1000), 'error' => null, 'at' => now()->toIso8601String()];
        Cache::put($key, $result, now()->addMinutes(self::KEEP_MINUTES));

        return $result;
    }

    /**
     * Each repository's own description, from theme.json or insert.php on its
     * default branch (read from raw.githubusercontent.com, which doesn't count
     * against GitHub's search limit), all at once.
     *
     * @return array<string, array>
     */
    protected static function details(string $kind, array $repos): array
    {
        $file = $kind === 'theme' ? 'theme.json' : 'insert.php';
        $found = [];
        $ask = [];
        foreach ($repos as $r) {
            $full = (string) $r['full_name'];
            $branch = preg_match('#^[A-Za-z0-9._/-]{1,100}$#', (string) ($r['default_branch'] ?? '')) ? (string) $r['default_branch'] : 'main';
            $key = 'booklet.catalog.repo.'.md5($full.'|'.$branch.'|'.($r['pushed_at'] ?? ''));
            if (is_array($kept = Cache::get($key))) {
                $found[$full] = $kept;
            } else {
                $ask[$full] = ['url' => 'https://raw.githubusercontent.com/'.$full.'/'.rawurlencode($branch).'/'.$file, 'key' => $key, 'branch' => $branch];
            }
        }
        if ($ask === []) {
            return $found;
        }

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($full, $a) => $pool->as($full)->withHeaders(['User-Agent' => 'Booklet'])->timeout(8)->connectTimeout(5)->get($a['url']),
                array_keys($ask), $ask,
            ));
        } catch (Throwable) {
            return $found;
        }

        foreach ($ask as $full => $a) {
            $response = $responses[$full] ?? null;
            $d = [];
            if ($response instanceof \Illuminate\Http\Client\Response && $response->successful() && strlen($response->body()) < 200_000) {
                $d = $kind === 'theme' ? self::fromThemeJson($response->body(), $full, $a['branch']) : self::fromInsertPhp($response->body());
            }
            Cache::put($a['key'], $d, now()->addMinutes($d === [] ? 5 : 6 * 60));
            $found[$full] = $d;
        }

        return $found;
    }

    protected static function fromThemeJson(string $json, string $full, string $branch): array
    {
        ['manifest' => $m] = ThemeManifest::read($json, '');
        if (($m['key'] ?? '') === '') {
            return [];
        }
        $preview = $m['preview'] && preg_match('/\.(png|jpe?g|webp|gif|svg)$/i', $m['preview'])
            ? 'https://raw.githubusercontent.com/'.$full.'/'.rawurlencode($branch).'/assets/'.implode('/', array_map('rawurlencode', explode('/', $m['preview'])))
            : null;

        return [
            'key' => $m['key'], 'name' => $m['name'], 'about' => $m['about'], 'version' => $m['version'],
            'author' => $m['author'], 'needs' => $m['needs'], 'scripts' => $m['js'] !== [], 'preview' => $preview,
        ];
    }

    protected static function fromInsertPhp(string $php): array
    {
        try {
            $m = ManifestReader::read($php);
        } catch (\ParseError) {
            return [];
        }
        if (! isset($m['name'])) {
            return [];
        }

        return [
            'key' => isset($m['key']) && preg_match('/^[a-z][a-z0-9-]{0,59}$/', $m['key']) ? $m['key'] : Str::slug($m['name']),
            'name' => $m['name'], 'about' => $m['about'] ?? '', 'version' => $m['version'] ?? null,
            'author' => $m['author'] ?? null, 'needs' => $m['needs'] ?? null,
        ];
    }

    /**
     * What this site has of one: null, or ['version' => …, 'newer' => bool].
     */
    public static function here(string $kind, array $item): ?array
    {
        $mine = $kind === 'theme' ? ThemeManager::find($item['key']) : app(InsertManager::class)->find($item['key']);
        if (! $mine) {
            return null;
        }

        return ['version' => $mine->version(), 'newer' => $item['version'] !== null && version_compare((string) $item['version'], $mine->version(), '>')];
    }

    public static function fits(array $item): bool
    {
        return empty($item['needs']) || version_compare((string) config('booklet.version', '1.0'), (string) $item['needs'], '>=');
    }

    protected static function headers(): array
    {
        $headers = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Booklet', 'X-GitHub-Api-Version' => '2022-11-28'];
        if ($token = config('booklet.github_token')) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $headers;
    }

    /** Not found this time: said plainly, and remembered briefly so each click doesn't wait for GitHub again. */
    protected static function failed(string $why, ?string $key = null): array
    {
        $result = ['items' => [], 'total' => 0, 'page' => 1, 'more' => false, 'error' => $why, 'at' => now()->toIso8601String()];
        if ($key) {
            Cache::put($key, $result, now()->addMinutes(2));
        }

        return $result;
    }
}
