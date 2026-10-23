<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\ContentApi;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate to the content API: off unless turned on in Settings, then a key
 * (sent as "Authorization: Bearer bk_…" or ?key=bk_…) unless it's open to
 * everyone. Answers browsers on the allowed origins, keeps to a fair pace,
 * and never lets a JSON answer be kept by a shared cache when a key was used.
 */
class ContentApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = ContentApi::settings();

        if ($request->isMethod('OPTIONS')) {
            return $this->cors($request, response()->noContent());
        }
        if (! $settings['on']) {
            return $this->cors($request, $this->error('The content API is turned off. An admin can turn it on in Settings › Content API.', 404));
        }

        $given = (string) ($request->bearerToken() ?? $request->query('key', ''));
        $key = $given !== '' ? ApiKey::findActive($given) : null;

        if ($given !== '' && ! $key) {
            return $this->cors($request, $this->error('That key isn’t one this site knows, or it’s been switched off.', 401));
        }
        if (! $key && ! $settings['open']) {
            return $this->cors($request, $this->error('A key is needed. Send it as “Authorization: Bearer bk_…”. Keys are made in Settings › Content API.', 401)
                ->header('WWW-Authenticate', 'Bearer realm="content"'));
        }

        $who = $key ? 'key:'.$key->id : 'ip:'.$request->ip();
        $limit = 'content-api|'.$who;
        if (RateLimiter::tooManyAttempts($limit, ContentApi::PER_MINUTE)) {
            return $this->cors($request, $this->error('Too many requests. Wait a minute and try again.', 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($limit)));
        }
        RateLimiter::hit($limit, 60);

        rescue(fn () => ApplySiteSettings::applyTimezone(), null, false);
        $key?->used();

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) ContentApi::PER_MINUTE);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($limit, ContentApi::PER_MINUTE));
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Cache-Control', ($key ? 'private' : 'public').', max-age=60');
        $response->headers->set('Vary', 'Authorization, Origin');

        // The same answer twice: say so, and send nothing.
        if ($response instanceof JsonResponse && $response->isOk()) {
            $response->setEtag(md5((string) $response->getContent()));
            $response->isNotModified($request);
        }

        return $this->cors($request, $response);
    }

    protected function error(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function cors(Request $request, Response $response): Response
    {
        $origin = $request->headers->get('Origin');
        if (ContentApi::allowsOrigin($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', in_array('*', ContentApi::settings()['origins'], true) ? '*' : (string) $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
            $response->headers->set('Access-Control-Max-Age', '3600');
        }

        return $response;
    }
}
