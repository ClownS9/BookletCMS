<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side guards on every page: no framing by other sites (so nobody
 * can lay the admin under a fake page and trick clicks), no guessing file
 * types, referrers trimmed to the site, only the features Booklet uses
 * (the microphone, for Stacy), and HTTPS kept once the site is on it.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $headers->set('Permissions-Policy', 'camera=(), geolocation=(), payment=(), usb=(), microphone=(self)', false);
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin', false);

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000', false);
        }

        // Signed-in pages (and sign-in itself) are never kept by shared caches or the back button after signing out.
        if ($request->is('admin', 'admin/*', 'login', 'setup', 'reset-password/*') && ! $headers->has('Content-Disposition')) {
            $headers->set('Cache-Control', 'no-store, private', true);
        }

        return $response;
    }
}
