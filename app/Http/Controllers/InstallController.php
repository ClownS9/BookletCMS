<?php

namespace App\Http\Controllers;

use App\Install\WebInstaller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The end of installing without a terminal (see App\Install\WebInstaller):
 * once .env is written, this makes the tables and the link to uploaded
 * files, then hands over to First setup. It answers only with the
 * one-time code the install page gave, within the hour.
 */
class InstallController extends Controller
{
    public const MINUTES = 60;

    public function finish(Request $request)
    {
        $file = base_path(WebInstaller::TOKEN_FILE);
        $saved = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $given = (string) $request->query('token', '');
        abort_unless(is_array($saved) && $given !== '' && hash_equals((string) ($saved['token'] ?? ''), hash('sha256', $given)), 404);
        if (time() - (int) ($saved['at'] ?? 0) > self::MINUTES * 60) {
            @unlink($file);
            abort(404);
        }

        @set_time_limit(0);
        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            report($e);

            return response()->view('install-problem', ['problem' => $e->getMessage(), 'retry' => $request->fullUrl()], 500);
        }

        // Uploaded files are served straight from public/storage where the host allows a link;
        // where it doesn't, Booklet hands them out itself (see the storage route).
        if (! is_dir(public_path('storage'))) {
            rescue(fn () => Artisan::call('storage:link'), null, false);
        }

        @unlink($file);

        return redirect()->route('setup');
    }

    /**
     * An uploaded file, when the host doesn't allow the public/storage link:
     * the web server didn't find it, so Booklet sends it from storage/app/public.
     */
    public function file(string $path)
    {
        abort_if(str_contains($path, '..') || str_starts_with(basename($path), '.'), 404);
        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        $headers = ['Cache-Control' => 'public, max-age=604800', 'X-Content-Type-Options' => 'nosniff'];
        // Never run as a page of the site (an uploaded drawing or document with scripts in it).
        if (preg_match('/\.(svgz?|html?|xml|xhtml)$/i', $path)) {
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox";
        }

        return response()->file($disk->path($path), $headers);
    }
}
