<?php

namespace App\Support;

use App\Booklet\Facades\Booklet;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Pictures at the right size. Every picture gets smaller WebP copies (made
 * the first time a browser asks for one, then kept), and pages list them in
 * srcset so phones fetch a small one and big screens a sharp one. Copies
 * keep the whole picture, so focal points work exactly as before.
 *
 * The copies live at storage/sized/{width}/{path}.webp on the public disk.
 * Once made, the web server hands them out as plain files; only a missing
 * one reaches Laravel (see PictureController), which makes it.
 */
class Pictures
{
    /** The widths copies are made at. */
    public const WIDTHS = [320, 640, 960, 1440, 1920];

    public const QUALITY = 80;

    public const FOLDER = 'sized';

    /** Booklet's own folders on the public disk: never shown in Media. */
    public const SYSTEM_FOLDERS = ['sized', 'cropped', 'share', 'avatars', 'site', 'bin', 'stacy-voice', 'backups', 'livewire-tmp'];

    /** Pictures this big (in pixels) are left as they are rather than risk running out of memory. */
    public const MAX_PIXELS = 50_000_000;

    protected const RESIZABLE = ['jpg', 'jpeg', 'png', 'webp'];

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagewebp');
    }

    public static function disk(): \Illuminate\Filesystem\FilesystemAdapter
    {
        return \App\Support\Disk::of('public');
    }

    /** Can this file on this container get sized copies? */
    public static function resizable(string $path, string $container = 'media'): bool
    {
        if (! self::available() || self::isSystem($path)) {
            return false;
        }
        $disk = Booklet::assetContainers()->find($container)?->attribute('disk') ?? 'public';

        return $disk === 'public'
            && config('filesystems.disks.public.driver') === 'local'
            && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::RESIZABLE, true);
    }

    public static function isSystem(string $path): bool
    {
        return in_array(Str::before(ltrim($path, '/'), '/'), self::SYSTEM_FOLDERS, true);
    }

    /** Where a copy lives (relative to the public disk). */
    public static function sizedPath(string $path, int $width): string
    {
        return self::FOLDER.'/'.$width.'/'.ltrim($path, '/').'.webp';
    }

    public static function url(string $path, int $width): string
    {
        return self::disk()->url(self::sizedPath($path, $width));
    }

    /**
     * "…/sized/320/cake.jpg.webp 320w, …, …/cake.jpg 2400w", or '' when the
     * picture can't (or needn't) have copies.
     */
    public static function srcset(?string $path, string $container = 'media'): string
    {
        if (! $path || ! self::resizable($path, $container)) {
            return '';
        }

        $width = self::widthOf($path, $container);
        if (! $width) {
            return '';
        }

        $candidates = [];
        foreach (self::WIDTHS as $w) {
            if ($w < $width) {
                $candidates[] = self::url($path, $w).' '.$w.'w';
            }
        }
        if ($candidates === []) {
            return '';
        }
        $candidates[] = self::disk()->url($path).' '.$width.'w';

        return implode(', ', $candidates);
    }

    /** The picture's width in pixels (from Media's notes about it, or by looking). */
    public static function widthOf(string $path, string $container = 'media'): ?int
    {
        static $seen = [];
        // Keyed by the file's time too, so a picture replaced at the same address is measured again.
        $key = $container.'|'.$path.'|'.(@filemtime(self::disk()->path($path)) ?: 0).'|'.(@filesize(self::disk()->path($path)) ?: 0);
        if (array_key_exists($key, $seen)) {
            return $seen[$key];
        }

        // Phone photos taken sideways are stored on their side: measure them the right way up.
        $jpeg = in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true);
        $width = $jpeg ? null : rescue(fn () => Booklet::assets()->find(['container' => $container, 'path' => $path])?->get('width'), null, false);
        if (! $width && self::disk()->exists($path)) {
            $width = self::uprightSize(self::disk()->path($path))[0] ?? null;
        }

        return $seen[$key] = $width ? (int) $width : null;
    }

    /**
     * Make one copy. Returns the copy's path, or null if it can't be made
     * (in which case the original should be used).
     */
    public static function make(string $path, int $width): ?string
    {
        $path = ltrim($path, '/');
        if (! in_array($width, self::WIDTHS, true) || str_contains($path, '..') || ! self::resizable($path, 'media')) {
            return null;
        }

        $disk = self::disk();
        $target = self::sizedPath($path, $width);
        if ($disk->exists($target)) {
            return $target;
        }
        if (! $disk->exists($path)) {
            return null;
        }

        $source = $disk->path($path);
        $info = @getimagesize($source);
        $upright = self::uprightSize($source);
        if (! $info || ! $upright || $upright[0] <= $width || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null; // already small enough (never made bigger), or too big to handle safely
        }

        @ini_set('memory_limit', max(256, (int) ceil($info[0] * $info[1] * 10 / 1048576) + 64).'M');
        $image = @imagecreatefromstring((string) file_get_contents($source));
        if (! $image) {
            return null;
        }
        $image = self::upright($image, $source, $info[2] ?? null);

        $height = (int) max(1, round(imagesy($image) * $width / imagesx($image)));
        $copy = imagecreatetruecolor($width, $height);
        imagealphablending($copy, false);
        imagesavealpha($copy, true);
        imagefill($copy, 0, 0, imagecolorallocatealpha($copy, 0, 0, 0, 127));
        imagecopyresampled($copy, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        imagedestroy($image);

        $full = $disk->path($target);
        @mkdir(dirname($full), 0755, true);
        $temp = $full.'.'.Str::random(6).'.tmp';
        $saved = imagewebp($copy, $temp, self::QUALITY);
        imagedestroy($copy);

        if (! $saved || ! @rename($temp, $full)) {
            @unlink($temp);

            return null;
        }

        return $target;
    }

    /** Throw away every copy of a picture (it was deleted or replaced). */
    public static function forget(string $path): void
    {
        foreach (self::WIDTHS as $width) {
            rescue(fn () => self::disk()->delete(self::sizedPath($path, $width)), null, false);
        }
        rescue(fn () => Crops::forget($path), null, false); // and its named sizes
    }

    /** A picture turned the right way up (phone photos are often stored on their side). */
    public static function uprightImage(\GdImage $image, string $file, ?int $type): \GdImage
    {
        return self::upright($image, $file, $type);
    }

    /** Throw away all copies (they're remade as needed). Returns how many went. */
    public static function clear(): int
    {
        $files = self::disk()->allFiles(self::FOLDER);
        self::disk()->deleteDirectory(self::FOLDER);

        return count($files) + Crops::clear();
    }

    /**
     * Pictures in written text (Markdown) that point at Media get srcset,
     * lazy loading and async decoding.
     */
    public static function enhanceHtml(string $html, string $sizes = '(max-width: 760px) 100vw, 720px'): string
    {
        $base = rtrim(self::disk()->url(''), '/').'/';
        $relative = (string) parse_url($base, PHP_URL_PATH);

        return (string) preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($base, $relative, $sizes) {
            $tag = $m[0];
            if (! preg_match('/\ssrc="([^"]+)"/i', $tag, $src) || preg_match('/\ssrcset=/i', $tag)) {
                return $tag;
            }

            $url = html_entity_decode($src[1]);
            $path = str_starts_with($url, $base) ? substr($url, strlen($base))
                : ($relative !== '' && str_starts_with($url, $relative) ? substr($url, strlen($relative)) : null);

            $extra = ' loading="lazy" decoding="async"';
            if ($path !== null && ($srcset = self::srcset(rawurldecode($path)))) {
                $extra .= ' srcset="'.e($srcset).'" sizes="'.e($sizes).'"';
            }

            return preg_replace('/\s*\/?>$/', $extra.'>', $tag, 1);
        }, $html);
    }

    /**
     * [width, height] as the picture is meant to be seen (sideways phone photos turned upright).
     *
     * @return array{0: int, 1: int}|null
     */
    public static function uprightSize(string $file): ?array
    {
        $info = @getimagesize($file);
        if (! $info) {
            return null;
        }
        $orientation = ($info[2] ?? null) === IMAGETYPE_JPEG && function_exists('exif_read_data')
            ? (int) (@exif_read_data($file)['Orientation'] ?? 1)
            : 1;

        return $orientation >= 5 && $orientation <= 8 ? [$info[1], $info[0]] : [$info[0], $info[1]];
    }

    /** Photos taken sideways on a phone come out the right way up. */
    protected static function upright(\GdImage $image, string $file, ?int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = (int) (@exif_read_data($file)['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        return $rotated ?: $image;
    }
}
