<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetching a file from another website on someone's say-so (pictures from a
 * WordPress export, say), without being talked into fetching something from
 * inside our own network: only http(s), only public addresses (checked again
 * at every redirect), and only up to a size.
 */
class SafeFetch
{
    public const MAX_BYTES = 25 * 1024 * 1024;

    /** How a site's name becomes addresses (replaceable in tests). */
    public static ?\Closure $resolve = null;

    /**
     * Download to a temporary file. Returns its path, or null (with $why set).
     * $headers are sent to $url only, not to wherever it redirects.
     */
    public static function download(string $url, ?string &$why = null, int $maxBytes = self::MAX_BYTES, array $headers = []): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'booklet-fetch-');

        for ($hop = 0; $hop <= 3; $hop++) {
            if (! self::allowed($url, $why)) {
                @unlink($tmp);

                return null;
            }

            try {
                // Extra headers (a sign-in token, say) only go to the address asked for, never on to a redirect.
                $response = Http::withHeaders(['User-Agent' => 'Booklet'] + ($hop === 0 ? $headers : []))
                    ->timeout(45)->connectTimeout(10)
                    ->withOptions(['allow_redirects' => false])
                    ->get($url);
            } catch (Throwable) {
                $why = 'the site didn’t answer';
                @unlink($tmp);

                return null;
            }

            if ($response->redirect() && ($next = $response->header('Location'))) {
                $url = self::absolute($next, $url);

                continue;
            }

            if (! $response->successful()) {
                $why = 'the site said '.$response->status();
                @unlink($tmp);

                return null;
            }

            if ((int) $response->header('Content-Length') > $maxBytes || strlen($response->body()) > $maxBytes) {
                $why = 'it’s bigger than '.round($maxBytes / 1048576).' MB';
                @unlink($tmp);

                return null;
            }

            file_put_contents($tmp, $response->body());

            return $tmp;
        }

        $why = 'too many redirects';
        @unlink($tmp);

        return null;
    }

    /** Is this an address on the public internet? */
    public static function allowed(string $url, ?string &$why = null): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            $why = 'it isn’t a web address';

            return false;
        }
        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443, 8080, 8443], true)) {
            $why = 'it uses an unusual port';

            return false;
        }

        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (self::$resolve ? (array) (self::$resolve)($host) : array_merge(
            (array) @gethostbynamel($host),
            array_column((array) @dns_get_record($host, DNS_AAAA), 'ipv6'),
        ));
        $ips = array_filter($ips);

        if ($ips === []) {
            $why = 'the site’s name couldn’t be found';

            return false;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $why = 'it points inside a private network';

                return false;
            }
        }

        return true;
    }

    protected static function absolute(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $root = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return str_starts_with($location, '/') ? $root.$location : $root.'/'.ltrim($location, '/');
    }
}
