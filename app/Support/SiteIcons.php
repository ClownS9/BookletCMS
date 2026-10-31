<?php

namespace App\Support;

use App\Booklet\Facades\Booklet;

/**
 * The little pictures a site is known by: the browser tab, a phone's home
 * screen, an Android app list. Two sets:
 *
 *  - "site", for the public site: an icon you uploaded, else your logo when
 *    it's square, else your initials on your accent colour, else Booklet's.
 *    (Or whichever of those you chose.)
 *  - "booklet", for the admin: Booklet's book on your accent colour, so its
 *    tab is easy to tell from your site's.
 *
 * Every size is drawn once with GD and kept in storage/app/booklet-icons,
 * under a signature of what it was drawn from, so a change makes new files
 * and the links (which carry the signature) point browsers at them.
 */
class SiteIcons
{
    /** Bump to redraw every set (a change in how they're drawn). */
    protected const DRAWING = 1;

    /** file => pixel size (null: not a fixed-size picture) */
    public const FILES = [
        'favicon.svg' => null,
        'favicon.ico' => 32,
        'icon-32.png' => 32,
        'apple-touch-icon.png' => 180,
        'icon-192.png' => 192,
        'icon-512.png' => 512,
    ];

    public const SOURCES = ['upload', 'logo', 'initials', 'booklet'];

    public const INK = '#1E1C18';

    /** Booklet's book, as the admin's own logo draws it (a 24 × 24 box). */
    protected const BOOK = 'M2 5c3-1.5 6.5-1.5 10 1 3.5-2.5 7-2.5 10-1v14c-3-1.5-6.5-1.5-10 1-3.5-2.5-7-2.5-10-1z';

    // What it's drawn from ----------------------------------------------------------------

    /**
     * The settings the icons depend on, from the site's globals, with any
     * unsaved changes laid over them (the settings page's preview).
     *
     * @return array{icon: ?string, icon_from: ?string, icon_letters: ?string, logo: ?string, accent: string, name: string}
     */
    public static function settings(array $over = []): array
    {
        $site = rescue(fn () => Booklet::globals('site'), null, false);
        $get = fn (string $key, $default = null) => $site?->get($key, $default) ?? $default;

        return array_merge([
            'icon' => $get('icon'),
            'icon_from' => $get('icon_from'),
            'icon_letters' => $get('icon_letters'),
            'logo' => $get('logo'),
            'accent' => (string) rescue(fn () => Look::current()['accent'], '#F4876A', false),
            'name' => (string) $get('site_name', config('app.name')),
        ], $over);
    }

    /** Where the site's icon comes from now: what was chosen, when it can be, else the first that can. */
    public static function source(?array $s = null): string
    {
        $s ??= self::settings();
        $can = [
            'upload' => (bool) $s['icon'] && self::exists($s['icon']),
            'logo' => (bool) $s['logo'] && self::exists($s['logo']),
            'initials' => self::initials($s['name'], $s['icon_letters']) !== '',
            'booklet' => true,
        ];
        if (in_array($s['icon_from'], self::SOURCES, true) && $can[$s['icon_from']]) {
            return $s['icon_from'];
        }
        if ($can['upload']) {
            return 'upload';
        }
        if ($can['logo'] && self::isSquare($s['logo'])) {
            return 'logo';
        }

        return $can['initials'] ? 'initials' : 'booklet';
    }

    /** One or two letters: the ones given, else the first letters of the name's first two words. */
    public static function initials(string $name, ?string $letters = null): string
    {
        $letters = trim((string) $letters);
        if ($letters !== '') {
            return mb_strtoupper(mb_substr(preg_replace('/\s+/u', '', $letters), 0, 2));
        }
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’]*/u', $name, $words);
        $firsts = array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words[0], 0, 2));

        return mb_strtoupper(implode('', $firsts));
    }

    /** Is a picture on the public disk (about) square? */
    public static function isSquare(string $path): bool
    {
        [$w, $h] = self::dimensions($path);

        return $w > 0 && $h > 0 && $w / $h >= 0.85 && $w / $h <= 1.18;
    }

    /** @return array{0: float, 1: float} */
    public static function dimensions(string $path): array
    {
        $file = self::path($path);
        if (! $file || ! is_file($file)) {
            return [0, 0];
        }
        if (self::isSvg($path)) {
            $svg = (string) file_get_contents($file, false, null, 0, 4096);
            if (preg_match('/viewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $svg, $m)) {
                return [(float) $m[1], (float) $m[2]];
            }
            preg_match('/<svg[^>]*\bwidth\s*=\s*["\']([\d.]+)/i', $svg, $w);
            preg_match('/<svg[^>]*\bheight\s*=\s*["\']([\d.]+)/i', $svg, $h);

            return [(float) ($w[1] ?? 0), (float) ($h[1] ?? 0)];
        }
        $size = @getimagesize($file);

        return $size ? [(float) $size[0], (float) $size[1]] : [0, 0];
    }

    // Links ----------------------------------------------------------------------------------

    /** The address of one of a set's files, carrying what it was drawn from. */
    public static function url(string $set, string $file): string
    {
        return route('icons', ['set' => $set, 'file' => $file]).'?v='.substr(self::signature($set), 0, 10);
    }

    /**
     * The <link>s for a page's <head>.
     *
     * @return array<int, array{rel: string, href: string, type?: string, sizes?: string}>
     */
    public static function links(string $set): array
    {
        $links = [];
        if (self::hasSvg($set)) {
            $links[] = ['rel' => 'icon', 'href' => self::url($set, 'favicon.svg'), 'type' => 'image/svg+xml'];
        }
        $links[] = ['rel' => 'icon', 'href' => self::url($set, 'icon-32.png'), 'type' => 'image/png', 'sizes' => '32x32'];
        $links[] = ['rel' => 'apple-touch-icon', 'href' => self::url($set, 'apple-touch-icon.png'), 'sizes' => '180x180'];
        if ($set === 'site') {
            $links[] = ['rel' => 'manifest', 'href' => route('site.manifest').'?v='.substr(self::signature($set), 0, 10)];
        }

        return $links;
    }

    /** The web app manifest: how Android names and pictures the site on a home screen. */
    public static function manifest(): array
    {
        $s = self::settings();
        $name = $s['name'] !== '' ? $s['name'] : 'Booklet';

        return [
            'name' => $name,
            'short_name' => mb_strlen($name) > 12 ? mb_substr($name, 0, 12) : $name,
            'start_url' => '/',
            'display' => 'browser',
            'theme_color' => $s['accent'],
            'background_color' => '#FFFFFF',
            'icons' => [
                ['src' => self::url('site', 'icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => self::url('site', 'icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ];
    }

    // Drawing ----------------------------------------------------------------------------------

    /** What a set is drawn from, as a short fingerprint. */
    public static function signature(string $set): string
    {
        $s = self::settings();
        if ($set === 'booklet') {
            return md5(self::DRAWING.'|booklet|'.$s['accent']);
        }
        $source = self::source($s);
        $picture = $source === 'upload' ? $s['icon'] : ($source === 'logo' ? $s['logo'] : null);
        $file = $picture ? self::path($picture) : null;

        return md5(implode('|', [self::DRAWING, $source, $s['accent'], self::initials($s['name'], $s['icon_letters']),
            (string) $picture, $file && is_file($file) ? filemtime($file).':'.filesize($file) : '']));
    }

    /** Is there an SVG for the tab? (Booklet's and initials always; a picture only when it is one.) */
    public static function hasSvg(string $set): bool
    {
        if ($set === 'booklet') {
            return true;
        }
        $s = self::settings();
        $source = self::source($s);

        return match ($source) {
            'upload' => self::isSvg((string) $s['icon']),
            'logo' => self::isSvg((string) $s['logo']),
            default => true,
        };
    }

    /**
     * One of a set's files, drawn if it isn't kept yet.
     *
     * @return array{0: string, 1: string}|null path and type
     */
    public static function file(string $set, string $file): ?array
    {
        if (! in_array($set, ['site', 'booklet'], true) || ! array_key_exists($file, self::FILES)) {
            return null;
        }
        if ($file === 'favicon.svg' && ! self::hasSvg($set)) {
            return null;
        }
        $type = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            default => 'image/png',
        };

        $folder = storage_path('app/booklet-icons/'.$set.'-'.substr(self::signature($set), 0, 16));
        $path = $folder.'/'.$file;
        if (is_file($path)) {
            return [$path, $type];
        }

        $bytes = self::draw($set, $file);
        if ($bytes === null) {
            return null;
        }
        if (! is_dir($folder)) {
            @mkdir($folder, 0775, true);
            self::tidy($set, $folder);
        }
        file_put_contents($path, $bytes);

        return [$path, $type];
    }

    /** The bytes of one file, drawn now. */
    public static function draw(string $set, string $file): ?string
    {
        $s = self::settings();
        $source = $set === 'booklet' ? 'booklet' : self::source($s);
        $size = self::FILES[$file] ?? null;
        // A tab icon is a rounded tile; the phone ones fill their square (the phone rounds it).
        $rounded = in_array($file, ['favicon.svg', 'favicon.ico', 'icon-32.png'], true);

        if ($file === 'favicon.svg') {
            return match ($source) {
                'upload' => (string) file_get_contents((string) self::path((string) $s['icon'])),
                'logo' => (string) file_get_contents((string) self::path((string) $s['logo'])),
                'initials' => self::initialsSvg(self::initials($s['name'], $s['icon_letters']), $s['accent']),
                default => self::bookSvg($s['accent']),
            };
        }

        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            return null;
        }

        if ($file === 'favicon.ico') {
            $pngs = [];
            foreach ([16, 32, 48] as $px) {
                $pngs[$px] = self::png($source, $s, $px, true);
            }

            return self::ico(array_filter($pngs));
        }

        return self::png($source, $s, (int) $size, $rounded);
    }

    /** A square PNG of the given size. An SVG picture can't be drawn here, so it falls back to the initials. */
    protected static function png(string $source, array $s, int $size, bool $rounded): ?string
    {
        $picture = $source === 'upload' ? $s['icon'] : ($source === 'logo' ? $s['logo'] : null);
        if ($picture && self::isSvg($picture)) {
            $letters = self::initials($s['name'], $s['icon_letters']);
            [$source, $picture] = [$letters !== '' ? 'initials' : 'booklet', null];
        }

        if ($picture) {
            $image = @imagecreatefromstring((string) file_get_contents((string) self::path($picture)));
            if (! $image) {
                return null;
            }
            // An icon fills its square; a logo fits inside it, with room around.
            $im = $source === 'upload' ? self::cover($image, $size) : self::contain($image, $size, $rounded ? 0.0 : 0.12);
            imagedestroy($image);
        } else {
            $im = $source === 'initials'
                ? self::initialsTile(self::initials($s['name'], $s['icon_letters']), $s['accent'], $size, $rounded)
                : self::bookTile($s['accent'], $size, $rounded);
        }

        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    protected static function cover(\GdImage $image, int $size): \GdImage
    {
        [$w, $h] = [imagesx($image), imagesy($image)];
        $side = min($w, $h);
        $im = self::blank($size);
        imagecopyresampled($im, $image, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $size, $size, $side, $side);

        return $im;
    }

    protected static function contain(\GdImage $image, int $size, float $room): \GdImage
    {
        [$w, $h] = [imagesx($image), imagesy($image)];
        $box = $size * (1 - 2 * $room);
        $scale = min($box / $w, $box / $h);
        [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
        $im = self::blank($size);
        imagecopyresampled($im, $image, (int) (($size - $nw) / 2), (int) (($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);

        return $im;
    }

    protected static function blank(int $size): \GdImage
    {
        $im = imagecreatetruecolor($size, $size);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagesavealpha($im, true);
        imagealphablending($im, true);

        return $im;
    }

    /** The accent tile, drawn four times bigger and shrunk, so its edges are smooth. */
    protected static function tile(string $accent, int $size, bool $rounded, callable $paint): \GdImage
    {
        $big = $size * 4;
        $im = self::blank($big);
        [$r, $g, $b] = self::rgb($accent);
        $fill = imagecolorallocate($im, $r, $g, $b);
        if ($rounded) {
            $radius = (int) round($big * 0.22);
            imagefilledrectangle($im, $radius, 0, $big - 1 - $radius, $big - 1, $fill);
            imagefilledrectangle($im, 0, $radius, $big - 1, $big - 1 - $radius, $fill);
            foreach ([[$radius, $radius], [$big - 1 - $radius, $radius], [$radius, $big - 1 - $radius], [$big - 1 - $radius, $big - 1 - $radius]] as [$cx, $cy]) {
                imagefilledellipse($im, $cx, $cy, $radius * 2, $radius * 2, $fill);
            }
        } else {
            imagefilledrectangle($im, 0, 0, $big - 1, $big - 1, $fill);
        }
        $paint($im, $big, Look::onAccent($accent));

        $out = self::blank($size);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $big, $big);
        imagedestroy($im);

        return $out;
    }

    protected static function initialsTile(string $letters, string $accent, int $size, bool $rounded): \GdImage
    {
        return self::tile($accent, $size, $rounded, function (\GdImage $im, int $big, string $on) use ($letters): void {
            $font = ShareImage::font('sans');
            [$r, $g, $b] = self::rgb($on);
            $ink = imagecolorallocate($im, $r, $g, $b);
            if (! is_file($font) || ! function_exists('imagettftext')) {
                return;
            }
            // The letters fill about half the tile, a little smaller for two wide ones.
            $pt = $big * (mb_strlen($letters) > 1 ? 0.40 : 0.50) * 0.75;
            for ($i = 0; $i < 6; $i++) {
                $box = imagettfbbox($pt, 0, $font, $letters);
                $width = $box[2] - $box[0];
                if ($width <= $big * 0.72) {
                    break;
                }
                $pt *= 0.9;
            }
            $box = imagettfbbox($pt, 0, $font, $letters);
            $width = $box[2] - $box[0];
            $height = $box[1] - $box[7];
            imagettftext($im, $pt, 0, (int) (($big - $width) / 2 - $box[0]), (int) (($big + $height) / 2 - $box[1]), $ink, $font, $letters);
        });
    }

    protected static function bookTile(string $accent, int $size, bool $rounded, ?string $ink = null): \GdImage
    {
        return self::tile($accent, $size, $rounded, function (\GdImage $im, int $big, string $on) use ($rounded): void {
            [$r, $g, $b] = self::rgb($on);
            $ink = imagecolorallocate($im, $r, $g, $b);
            // The 24-unit book, drawn about 62% of the tile (smaller on a phone's full square).
            $scale = $big * ($rounded ? 0.66 : 0.56) / 20;
            $ox = $big / 2 - 12 * $scale;
            $oy = $big / 2 - 12.5 * $scale;
            $pt = fn (float $x, float $y) => [$ox + $x * $scale, $oy + $y * $scale];
            $line = max(2, (int) round(2.0 * $scale));

            // The cover's outline: two curves along the top, the sides, two curves along the bottom.
            $curve = function (array $a, array $c1, array $c2, array $z): array {
                $points = [];
                for ($t = 0; $t <= 1.0001; $t += 1 / 24) {
                    $u = 1 - $t;
                    $points[] = [
                        $u ** 3 * $a[0] + 3 * $u ** 2 * $t * $c1[0] + 3 * $u * $t ** 2 * $c2[0] + $t ** 3 * $z[0],
                        $u ** 3 * $a[1] + 3 * $u ** 2 * $t * $c1[1] + 3 * $u * $t ** 2 * $c2[1] + $t ** 3 * $z[1],
                    ];
                }

                return $points;
            };
            $outline = array_merge(
                $curve([2, 5], [5, 3.5], [8.5, 3.5], [12, 6]),
                $curve([12, 6], [15.5, 3.5], [19, 3.5], [22, 5]),
                [[22, 19]],
                $curve([22, 19], [19, 17.5], [15.5, 17.5], [12, 20]),
                $curve([12, 20], [8.5, 17.5], [5, 17.5], [2, 19]),
                [[2, 5]],
            );
            $stroke = function (array $points) use ($im, $ink, $pt, $line): void {
                for ($i = 1; $i < count($points); $i++) {
                    [$x1, $y1] = $pt(...$points[$i - 1]);
                    [$x2, $y2] = $pt(...$points[$i]);
                    self::thick($im, $x1, $y1, $x2, $y2, $line, $ink);
                }
            };
            $stroke($outline);
            $stroke([[12, 6], [12, 20]]);
        });
    }

    /** A thick line with round ends (GD's own thick lines have square, gappy joins). */
    protected static function thick(\GdImage $im, float $x1, float $y1, float $x2, float $y2, int $width, int $color): void
    {
        $steps = max(1, (int) ceil(hypot($x2 - $x1, $y2 - $y1) / max(1, $width / 3)));
        for ($i = 0; $i <= $steps; $i++) {
            $t = $i / $steps;
            imagefilledellipse($im, (int) round($x1 + ($x2 - $x1) * $t), (int) round($y1 + ($y2 - $y1) * $t), $width, $width, $color);
        }
    }

    public static function bookSvg(string $accent): string
    {
        $on = Look::onAccent($accent);

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="'.e($accent).'"/>'
            .'<g transform="translate(4.6 4.3) scale(.95)" fill="none" stroke="'.$on.'" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">'
            .'<path d="'.self::BOOK.'"/><path d="M12 6v14"/></g></svg>';
    }

    public static function initialsSvg(string $letters, string $accent): string
    {
        $on = Look::onAccent($accent);
        $size = mb_strlen($letters) > 1 ? 14 : 18;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="'.e($accent).'"/>'
            .'<text x="16" y="16" dy=".36em" text-anchor="middle" font-family="Instrument Sans, Inter, Segoe UI, Helvetica, Arial, sans-serif" font-weight="600" font-size="'.$size.'" fill="'.$on.'">'.e($letters).'</text></svg>';
    }

    /**
     * An .ico holding PNGs (every browser since IE Vista reads these).
     *
     * @param  array<int, string>  $pngs  size => PNG bytes
     */
    public static function ico(array $pngs): string
    {
        $head = pack('vvv', 0, 1, count($pngs));
        $dir = '';
        $body = '';
        $offset = 6 + 16 * count($pngs);
        foreach ($pngs as $px => $png) {
            $dir .= pack('CCCCvvVV', $px >= 256 ? 0 : $px, $px >= 256 ? 0 : $px, 0, 0, 1, 32, strlen($png), $offset);
            $body .= $png;
            $offset += strlen($png);
        }

        return $head.$dir.$body;
    }

    // Small things ---------------------------------------------------------------------------

    public static function isSvg(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg';
    }

    protected static function exists(string $path): bool
    {
        $file = self::path($path);

        return $file !== null && is_file($file);
    }

    /** A picture on the public disk, as a file on this server. */
    protected static function path(string $path): ?string
    {
        return rescue(fn () => Disk::of('public')->path($path), null, false);
    }

    /** @return array{0: int, 1: int, 2: int} */
    protected static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (! preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            $hex = 'F4876A';
        }

        return array_map('hexdec', str_split($hex, 2));
    }

    /** Older drawings of the same set go when a new one is made. */
    protected static function tidy(string $set, string $keep): void
    {
        foreach (glob(storage_path('app/booklet-icons/'.$set.'-*'), GLOB_ONLYDIR) ?: [] as $old) {
            if ($old !== $keep) {
                foreach (glob($old.'/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($old);
            }
        }
    }
}
