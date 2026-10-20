<?php

namespace App\Support;

use App\Booklet\Data\Asset;
use App\Booklet\Facades\Booklet;

/**
 * A picture's focal point: the spot that matters (a face, the cake), kept in
 * view wherever the picture is cropped. Stored on the file's details as
 * "x-y-zoom" in percent, the way Statamic does ("50-30-1"), and used as CSS
 * object-position, so nothing about the picture itself changes.
 */
class Focus
{
    /** @return array{0: int, 1: int} x and y, 0–100 */
    public static function parse(?string $focus): array
    {
        if (! $focus || ! preg_match('/^(\d{1,3}(?:\.\d+)?)-(\d{1,3}(?:\.\d+)?)/', $focus, $m)) {
            return [50, 50];
        }

        return [self::clamp((float) $m[1]), self::clamp((float) $m[2])];
    }

    public static function store(int|float $x, int|float $y): string
    {
        return self::clamp($x).'-'.self::clamp($y).'-1';
    }

    /** For CSS: "40% 25%". */
    public static function css(?string $focus): string
    {
        [$x, $y] = self::parse($focus);

        return "{$x}% {$y}%";
    }

    public static function of(?Asset $asset): string
    {
        return self::css($asset?->get('focus'));
    }

    public static function forPath(?string $path, string $container = 'media'): string
    {
        if (! $path) {
            return '50% 50%';
        }

        // Remembered for this request only (a page of cards asks about the same pictures).
        $key = 'booklet.focus.'.$container.'::'.$path;
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, self::of(
                rescue(fn () => Booklet::assets()->find(['container' => $container, 'path' => $path]), null, false)
            ));
        }

        return request()->attributes->get($key);
    }

    protected static function clamp(int|float $n): int
    {
        return (int) round(max(0, min(100, $n)));
    }
}
