<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use Illuminate\Support\Str;

/**
 * Named picture sizes (Statamic's Glide presets): "card", "hero", "square"…
 * Each is a shape and a width; every picture in Media can be had in each,
 * cut around its focal point. A crop is cut exactly the way CSS
 * object-position shows the picture (the focal point's x% of the picture
 * lines up with x% of the crop), so a crop looks like the picture already
 * looked in that shape on the site, only smaller to download.
 *
 * Crops live at storage/cropped/{size}/{w}x{h}-{x}-{y}/{path}.webp. The focal
 * point and the shape are in the address, so moving the focal point or
 * changing a size gives new addresses and old copies are simply left behind
 * (Pictures::clear and Crops::clear tidy them away).
 */
class Crops
{
    public const FOLDER = 'cropped';

    /** Booklet's own sizes, until someone changes them in Media › Sizes. */
    public const DEFAULTS = [
        'card' => ['label' => 'Card', 'width' => 1200, 'height' => 900],
        'hero' => ['label' => 'Hero', 'width' => 1920, 'height' => 820],
        'square' => ['label' => 'Square', 'width' => 800, 'height' => 800],
        'tall' => ['label' => 'Tall', 'width' => 800, 'height' => 1000],
        'share' => ['label' => 'Share', 'width' => 1200, 'height' => 630],
    ];

    public const MAX_SIDE = 3000;

    public const MAX_SIZES = 12;

    /**
     * The sizes in use: [handle => [label, width, height]].
     *
     * @return array<string, array{label: string, width: int, height: int}>
     */
    public static function sizes(): array
    {
        $saved = rescue(fn () => Booklet::globals('site')?->get('picture_sizes'), null, false);

        return is_array($saved) && $saved !== [] ? self::clean($saved) : self::DEFAULTS;
    }

    /** Sizes from a form or a file, made safe: handles, whole pixels within reason. */
    public static function clean(array $sizes): array
    {
        $out = [];
        foreach ($sizes as $handle => $size) {
            $handle = Str::snake(Str::slug((string) $handle, ' '));
            $width = (int) ($size['width'] ?? 0);
            $height = (int) ($size['height'] ?? 0);
            if ($handle === '' || ! preg_match('/^[a-z][a-z0-9_]{0,30}$/', $handle) || $width < 16 || $height < 16
                || $width > self::MAX_SIDE || $height > self::MAX_SIDE || count($out) >= self::MAX_SIZES) {
                continue;
            }
            $out[$handle] = ['label' => Str::limit(trim((string) ($size['label'] ?? '')) ?: Str::headline($handle), 40, ''), 'width' => $width, 'height' => $height];
        }

        return $out;
    }

    public static function save(array $sizes): void
    {
        $globals = Booklet::globals('site') ?? Booklet::globalVariables()->make(['handle' => 'site', 'locale' => config('booklet.default_site')]);
        $globals->set('picture_sizes', self::clean($sizes))->save();
        app()->forgetInstance(\App\Booklet\Site::class);
    }

    /** "3:2", "1:1", "21:9"… for people. */
    public static function ratio(int $width, int $height): string
    {
        [$a, $b] = [$width, $height];
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        $d = max(1, $a);
        [$w, $h] = [$width / $d, $height / $d];

        // Near-enough common shapes read better than 192:82.
        foreach (['1:1' => 1, '4:5' => .8, '3:4' => .75, '2:3' => 2 / 3, '3:2' => 1.5, '4:3' => 4 / 3, '16:9' => 16 / 9, '1.91:1' => 1.91, '21:9' => 21 / 9, '5:2' => 2.5] as $name => $r) {
            if (abs($width / $height - $r) < 0.02) {
                return $name;
            }
        }

        return $w <= 50 && $h <= 50 ? "{$w}:{$h}" : number_format($width / $height, 2).':1';
    }

    // Addresses -------------------------------------------------------------------------------

    /**
     * The crop's address for a picture in Media (or its own address when it
     * can't be cropped: not a picture, kept elsewhere, or no such size).
     */
    public static function url(?string $path, string $size, string $container = 'media', ?int $width = null): ?string
    {
        if (! $path) {
            return null;
        }
        $spec = self::spec($path, $size, $container, $width);
        if ($spec === null) {
            $disk = Booklet::assetContainers()->find($container)?->attribute('disk') ?? 'public';

            return Disk::of($disk)->url($path);
        }

        return Pictures::disk()->url(self::FOLDER.'/'.$size.'/'.$spec.'/'.ltrim($path, '/').'.webp');
    }

    /** Full size and half size, for srcset ('' when there's nothing to choose between). */
    public static function srcset(?string $path, string $size, string $container = 'media'): string
    {
        $sizes = self::sizes();
        if (! $path || ! isset($sizes[$size]) || ! Pictures::resizable($path, $container)) {
            return '';
        }
        $full = self::outputSize($path, $size, $container);
        if (! $full) {
            return '';
        }
        $candidates = [];
        $half = (int) round($full[0] / 2);
        if ($half >= 320) {
            $candidates[] = self::url($path, $size, $container, $half).' '.$half.'w';
        }
        $candidates[] = self::url($path, $size, $container).' '.$full[0].'w';

        return count($candidates) > 1 ? implode(', ', $candidates) : '';
    }

    /**
     * "{w}x{h}-{x}-{y}": the crop's size and focal point, as it appears in the
     * address. Null when the picture can't be cropped.
     */
    public static function spec(string $path, string $size, string $container = 'media', ?int $width = null): ?string
    {
        if (! isset(self::sizes()[$size]) || ! Pictures::resizable($path, $container)) {
            return null;
        }
        $out = self::outputSize($path, $size, $container, $width);
        if (! $out) {
            return null;
        }
        [$x, $y] = Focus::parse(rescue(fn () => Booklet::assets()->find(['container' => $container, 'path' => $path])?->get('focus'), null, false));

        return "{$out[0]}x{$out[1]}-{$x}-{$y}";
    }

    /**
     * How big the crop comes out: the size's own width, or $width when asked
     * for a smaller one, never bigger than the picture allows (never enlarged).
     *
     * @return array{0: int, 1: int}|null
     */
    protected static function outputSize(string $path, string $size, string $container, ?int $width = null): ?array
    {
        $preset = self::sizes()[$size] ?? null;
        $source = Pictures::widthOf($path, $container);
        $sourceHeight = self::heightOf($path);
        if (! $preset || ! $source || ! $sourceHeight) {
            return null;
        }
        $ratio = $preset['width'] / $preset['height'];
        // The biggest box of that shape inside the picture.
        $boxW = min($source, $sourceHeight * $ratio);
        $w = (int) min($width ?: $preset['width'], $preset['width'], floor($boxW));
        $h = (int) max(1, round($w / $ratio));

        return [$w, $h];
    }

    protected static function heightOf(string $path): ?int
    {
        static $seen = [];
        $file = Pictures::disk()->path($path);
        $key = $path.'|'.(@filemtime($file) ?: 0);

        return $seen[$key] ??= (Pictures::uprightSize($file)[1] ?? null);
    }

    // Making ------------------------------------------------------------------------------------

    /**
     * Make one crop, for an address that came in (CropController). The address
     * must be one this site would hand out right now, so nobody can ask for
     * any size they like. Returns the crop's path, or null.
     */
    public static function make(string $size, string $spec, string $path): ?string
    {
        $path = ltrim($path, '/');
        if (str_contains($path, '..') || ! preg_match('/^(\d{1,4})x(\d{1,4})-(\d{1,3})-(\d{1,3})$/', $spec, $m)) {
            return null;
        }
        [$w, $h, $x, $y] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];

        // Only the full size or the half size this site lists for the picture.
        $current = self::spec($path, $size);
        $half = self::spec($path, $size, 'media', (int) round((int) explode('x', (string) $current)[0] / 2));
        if ($current === null || ! in_array($spec, [$current, $half], true)) {
            return null;
        }

        $disk = Pictures::disk();
        $target = self::FOLDER.'/'.$size.'/'.$spec.'/'.$path.'.webp';
        if ($disk->exists($target)) {
            return $target;
        }
        $source = $disk->path($path);
        $info = @getimagesize($source);
        if (! $info || $info[0] * $info[1] > Pictures::MAX_PIXELS) {
            return null;
        }

        @ini_set('memory_limit', max(256, (int) ceil($info[0] * $info[1] * 10 / 1048576) + 64).'M');
        $image = @imagecreatefromstring((string) file_get_contents($source));
        if (! $image) {
            return null;
        }
        $image = Pictures::uprightImage($image, $source, $info[2]);
        [$sw, $sh] = [imagesx($image), imagesy($image)];

        // The biggest box of the crop's shape, placed the way object-position would.
        $ratio = $w / $h;
        $boxW = (int) min($sw, round($sh * $ratio));
        $boxH = (int) min($sh, round($boxW / $ratio));
        $left = (int) round(($sw - $boxW) * $x / 100);
        $top = (int) round(($sh - $boxH) * $y / 100);

        $copy = imagecreatetruecolor($w, $h);
        imagealphablending($copy, false);
        imagesavealpha($copy, true);
        imagefill($copy, 0, 0, imagecolorallocatealpha($copy, 0, 0, 0, 127));
        imagecopyresampled($copy, $image, 0, 0, $left, $top, $w, $h, $boxW, $boxH);
        imagedestroy($image);

        $full = $disk->path($target);
        @mkdir(dirname($full), 0755, true);
        $temp = $full.'.'.Str::random(6).'.tmp';
        $saved = imagewebp($copy, $temp, Pictures::QUALITY);
        imagedestroy($copy);
        if (! $saved || ! @rename($temp, $full)) {
            @unlink($temp);

            return null;
        }

        return $target;
    }

    /** Every crop of one picture (it was deleted or replaced). */
    public static function forget(string $path): void
    {
        $disk = Pictures::disk();
        foreach ($disk->directories(self::FOLDER) as $sizeDir) {
            foreach ($disk->directories($sizeDir) as $specDir) {
                rescue(fn () => $disk->delete($specDir.'/'.ltrim($path, '/').'.webp'), null, false);
            }
        }
    }

    public static function clear(): int
    {
        $disk = Pictures::disk();
        $count = count($disk->allFiles(self::FOLDER));
        $disk->deleteDirectory(self::FOLDER);

        return $count;
    }

    // For themes ------------------------------------------------------------------------------

    /** An entry's picture field in a named size: ['url', 'srcset', 'alt', 'width', 'height'] or null. */
    public static function forEntry(Entry $entry, string $size, string $field = 'cover', string $container = 'media'): ?array
    {
        $path = ((array) $entry->get($field, []))[0] ?? null;
        if (! is_string($path) || $path === '') {
            return null;
        }
        $spec = self::spec($path, $size, $container);
        [$w, $h] = $spec ? array_map('intval', explode('x', explode('-', $spec)[0])) : [null, null];

        return [
            'url' => self::url($path, $size, $container),
            'srcset' => self::srcset($path, $size, $container),
            'alt' => (string) rescue(fn () => Booklet::assets()->find(['container' => $container, 'path' => $path])?->get('alt', ''), '', false),
            'width' => $w,
            'height' => $h,
            'cropped' => $spec !== null,
        ];
    }
}
