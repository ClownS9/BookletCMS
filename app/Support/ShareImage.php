<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Site;

/**
 * The picture that shows when a link is shared (WhatsApp, Facebook, X,
 * Slack…) for posts and pages without a picture of their own: a page from
 * the book with the title in Newsreader, the standfirst, the byline, a
 * sticker in the site's accent colour and a folded corner.
 *
 * Made the first time someone asks for it (ShareImageController) and kept at
 * storage/share/{id}-{fingerprint}.png; a new title or colour makes a new one.
 */
class ShareImage
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    public const FOLDER = 'share';

    /** Bump to remake every card after a design change. */
    protected const VERSION = 1;

    public static function available(): bool
    {
        return function_exists('imagettftext') && function_exists('imagepng') && is_file(self::font('display'));
    }

    /** The card's address for an entry, or null if one can't be made (no GD, or letters the font hasn't got). */
    public static function urlFor(Entry $entry): ?string
    {
        if (! self::available() || ! self::drawable($entry->title())) {
            return null;
        }

        return \App\Support\Disk::of('public')->url(self::FOLDER.'/'.$entry->id().'-'.self::fingerprint($entry).'.png');
    }

    /** A card for the site itself (the home page, the blog, search). */
    public static function siteUrl(): ?string
    {
        $site = app(Site::class);
        if (! self::available() || ! self::drawable($site->name())) {
            return null;
        }

        return \App\Support\Disk::of('public')->url(self::FOLDER.'/site-'.self::siteFingerprint().'.png');
    }

    public static function fingerprint(Entry $entry): string
    {
        $site = app(Site::class);

        return substr(md5(implode('|', [self::VERSION, $entry->title(), $entry->get('excerpt'), $site->name(), $site->accent(), $entry->date()?->toDateString(), (string) $entry->get('author')])), 0, 10);
    }

    public static function siteFingerprint(): string
    {
        $site = app(Site::class);

        return substr(md5(implode('|', [self::VERSION, $site->name(), $site->tagline(), $site->accent(), $site->absolute(url('/'))])), 0, 10);
    }

    /** Can our fonts draw this? (Latin, Greek and Cyrillic letters, and the usual punctuation.) */
    public static function drawable(string $text): bool
    {
        return trim($text) !== '' && ! preg_match('/[^\x{0000}-\x{052F}\x{1E00}-\x{1EFF}\x{2000}-\x{206F}\x{20A0}-\x{20CF}\x{2100}-\x{214F}]/u', $text);
    }

    // Drawing ------------------------------------------------------------------

    /** Draw the card for an entry and return the PNG bytes. */
    public static function draw(Entry $entry): string
    {
        $site = app(Site::class);
        $author = $site->author($entry);
        $meta = array_filter([
            $author ? ($author->byline ?: $author->name) : null,
            $entry->collection()?->dated() ? $entry->date()?->format('j F Y') : null,
        ]);

        return self::canvas(
            sticker: $site->name(),
            title: $entry->title() ?: 'Untitled',
            standfirst: trim((string) ($entry->get('excerpt') ?: '')) ?: null,
            meta: implode('  ·  ', $meta),
            domain: preg_replace('#^https?://(www\.)?#', '', rtrim($site->absolute(url('/')), '/')),
            accent: $site->accent(),
        );
    }

    public static function drawSite(): string
    {
        $site = app(Site::class);

        return self::canvas(
            sticker: 'Read online',
            title: $site->name(),
            standfirst: $site->tagline(),
            meta: '',
            domain: preg_replace('#^https?://(www\.)?#', '', rtrim($site->absolute(url('/')), '/')),
            accent: $site->accent(),
        );
    }

    protected static function canvas(string $sticker, string $title, ?string $standfirst, string $meta, string $domain, string $accent): string
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, true);
        imagesavealpha($im, true);

        $c = fn (string $hex, int $alpha = 0) => imagecolorallocatealpha($im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)), $alpha);
        $desk = $c('#23211D');
        $paper = $c('#FBF8F1');
        $edge1 = $c('#EFE9DC');
        $edge2 = $c('#E2DACA');
        $ink = $c('#1E1C18');
        $muted = $c('#4A453C');
        $faint = $c('#6B655A');
        $rule = $c('#E4DDCE');
        $accentC = $c(preg_match('/^#[0-9a-f]{6}$/i', $accent) ? $accent : '#F4876A');

        // The desk, and a page with two leaves showing beneath it.
        imagefilledrectangle($im, 0, 0, $w, $h, $desk);
        $x0 = 44; $y0 = 40; $x1 = $w - 44; $y1 = $h - 40;
        imagefilledrectangle($im, $x0 + 10, $y0 + 10, $x1 + 10, $y1 + 10, $edge2);
        imagefilledrectangle($im, $x0 + 5, $y0 + 5, $x1 + 5, $y1 + 5, $edge1);
        imagefilledrectangle($im, $x0, $y0, $x1, $y1, $paper);
        // a soft fold shadow along the left, like the gutter of a book
        for ($i = 0; $i < 26; $i++) {
            imageline($im, $x0 + $i, $y0, $x0 + $i, $y1, $c('#1E1C18', 127 - (int) (22 * (1 - $i / 26))));
        }

        // The folded corner (top right).
        $fold = 74;
        imagefilledpolygon($im, [$x1 - $fold, $y0, $x1, $y0, $x1, $y0 + $fold], $desk);
        imagefilledpolygon($im, [$x1 - $fold, $y0, $x1 - $fold, $y0 + $fold, $x1, $y0 + $fold], $edge2);
        imageline($im, $x1 - $fold, $y0, $x1, $y0 + $fold, $c('#CFC6B3'));

        // The sticker with the site's name, stuck on a little crooked.
        $sans = self::font('sans');
        $sx = $x0 + 76; $sy = $y0 + 58;
        self::sticker($im, mb_strtoupper(mb_substr($sticker, 0, 40)), $sx, $sy, $accent);

        // The title: as big as fits in four lines.
        $display = self::font('display');
        $left = $x0 + 76;
        $maxWidth = $x1 - $left - 110;
        foreach ([66, 60, 54, 48, 43, 38] as $size) {
            $lines = self::wrap($title, $display, $size, $maxWidth);
            if (count($lines) <= ($standfirst ? 3 : 4)) {
                break;
            }
        }
        $lines = array_slice($lines, 0, 4);
        $lineHeight = (int) round($size * 1.22);
        $y = $sy + 76 + $size;
        foreach ($lines as $line) {
            imagettftext($im, $size, 0, $left, $y, $ink, $display, $line);
            $y += $lineHeight;
        }

        // The standfirst, in italics, if there's room.
        $base = $y1 - 52;
        $last = $y - $lineHeight; // the title's last line
        if ($standfirst && $last < $base - 120) {
            $italic = self::font('italic');
            $more = self::wrap($standfirst, $italic, 26, $maxWidth);
            $room = max(1, min(3, (int) floor(($base - 60 - ($last + 54)) / 38) + 1));
            $y = $last + 54;
            foreach (array_slice($more, 0, $room) as $i => $line) {
                if ($i === $room - 1 && count($more) > $room) {
                    $line = rtrim($line, ' ,.;:').'…';
                }
                imagettftext($im, 26, 0, $left, $y, $muted, $italic, $line);
                $y += 38;
            }
        }

        // A rule, the byline, and the site's address.
        imageline($im, $left, $base - 36, $x1 - 76, $base - 36, $rule);
        imagefilledrectangle($im, $left, $base - 38, $left + 56, $base - 35, $accentC);
        $dbox = imagettfbbox(19, 0, $sans, $domain);
        $domainWidth = $dbox[2] - $dbox[0];
        imagettftext($im, 19, 0, $x1 - 76 - $domainWidth, $base, $faint, $sans, $domain);
        if ($meta !== '') {
            $meta = mb_strtoupper($meta);
            while (mb_strlen($meta) > 4 && self::spacedWidth($meta, $sans, 16, 2) > $x1 - 76 - $domainWidth - 40 - $left) {
                $meta = rtrim(mb_substr($meta, 0, -2)).'…';
            }
            self::spacedText($im, 16, $left, $base - 1, $faint, $sans, $meta, 2);
        }

        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    /** Words onto lines no wider than $width. */
    protected static function wrap(string $text, string $font, int $size, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            $box = imagettfbbox($size, 0, $font, $try);
            if ($box[2] - $box[0] > $width && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /** Small capitals with room between the letters (GD has no letter-spacing, so one letter at a time). */
    protected static function spacedText(\GdImage $im, int $size, int $x, int $y, int $color, string $font, string $text, int $spacing): int
    {
        foreach (mb_str_split($text) as $char) {
            $box = imagettftext($im, $size, 0, $x, $y, $color, $font, $char);
            $x = $box[2] + ($char === ' ' ? 0 : $spacing) + ($char === ' ' ? (int) round($size * .28) : 0);
        }

        return $x;
    }

    protected static function spacedWidth(string $text, string $font, int $size, int $spacing): int
    {
        $width = 0;
        foreach (mb_str_split($text) as $char) {
            $box = imagettfbbox($size, 0, $font, $char);
            $width += ($box[2] - $box[0]) + ($char === ' ' ? (int) round($size * .28) : $spacing);
        }

        return $width;
    }

    /** The site's name on a coloured sticker, drawn flat then turned a little. */
    protected static function sticker(\GdImage $im, string $label, int $x, int $y, string $accent): void
    {
        $font = self::font('sans');
        $size = 16;
        $w = self::spacedWidth($label, $font, $size, 2) + 34;
        $h = 44;
        $pad = 8;

        $tag = imagecreatetruecolor($w + $pad * 2, $h + $pad * 2);
        imagesavealpha($tag, true);
        imagealphablending($tag, false);
        imagefill($tag, 0, 0, imagecolorallocatealpha($tag, 0, 0, 0, 127));
        imagealphablending($tag, true);
        $hex = preg_match('/^#[0-9a-f]{6}$/i', $accent) ? $accent : '#F4876A';
        $fill = imagecolorallocate($tag, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
        imagefilledrectangle($tag, $pad, $pad, $pad + $w, $pad + $h, $fill);
        self::spacedText($tag, $size, $pad + 17, $pad + 29, imagecolorallocate($tag, 30, 28, 24), $font, $label, 2);

        $turned = imagerotate($tag, 2.2, imagecolorallocatealpha($tag, 0, 0, 0, 127));
        imagealphablending($turned, true);
        imagesavealpha($turned, true);
        imagecopy($im, $turned, $x - $pad, $y - $pad, 0, 0, imagesx($turned), imagesy($turned));
        imagedestroy($tag);
        imagedestroy($turned);
    }

    public static function font(string $which): string
    {
        return resource_path('fonts/'.[
            'display' => 'Newsreader-Display.ttf',
            'italic' => 'Newsreader-Italic.ttf',
            'sans' => 'InstrumentSans-SemiBold.ttf',
        ][$which]);
    }
}
