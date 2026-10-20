<?php

namespace App\Support;

/**
 * File sizes in words people use ("1.2 MB"), without needing PHP's intl
 * extension (Laravel's Number helpers do, and many hosts don't have it).
 */
class Sizes
{
    public static function file(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = max(0, $bytes);
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        $number = $unit === 0 ? (string) $size : number_format($size, $size < 10 ? 1 : 0, '.', '');
        if (str_contains($number, '.')) {
            $number = rtrim(rtrim($number, '0'), '.'); // "1.0" → "1", never "240" → "24"
        }

        return $number.' '.$units[$unit];
    }
}
