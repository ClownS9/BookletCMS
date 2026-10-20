<?php

namespace App\Booklet\Data;

/**
 * Metadata for a file in a Media container. The file itself is on a Laravel
 * disk; this holds alt text, dimensions, size and focus point in its data bag.
 */
class Asset extends Item
{
    public static function repositoryName(): string
    {
        return 'assets_meta';
    }

    /**
     * Folder, basename, filename and extension from a path like "covers/night.jpg".
     */
    public static function partsFromPath(string $container, string $path): array
    {
        $path = trim($path, '/');
        $folder = str_contains($path, '/') ? dirname($path) : '/';
        $basename = basename($path);
        $extension = pathinfo($basename, PATHINFO_EXTENSION);

        return [
            'container' => $container,
            'path' => $path,
            'folder' => $folder,
            'basename' => $basename,
            'filename' => pathinfo($basename, PATHINFO_FILENAME),
            'extension' => strtolower($extension),
        ];
    }

    public function container(): string
    {
        return $this->attribute('container');
    }

    public function path(): string
    {
        return $this->attribute('path');
    }

    public function isImage(): bool
    {
        return in_array($this->attribute('extension'), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'], true);
    }
}
