<?php

namespace App\Booklet\Support;

use App\Booklet\Data\Asset;
use App\Booklet\Facades\Booklet;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Puts files into a Media container and records their meta. Shared by the
 * Media chapter and the editor's picture picker.
 */
class AssetUploader
{
    public const MIMES = 'jpg,jpeg,png,gif,webp,avif,svg,pdf,mp3,m4a,wav,mp4,mov,webm,zip,txt,csv,doc,docx,xls,xlsx,ppt,pptx';

    public function __construct(protected string $container) {}

    public function disk(): \Illuminate\Filesystem\FilesystemAdapter
    {
        $disk = Booklet::assetContainers()->find($this->container)?->attribute('disk') ?? 'public';

        return \App\Support\Disk::of($disk);
    }

    public function store(UploadedFile $file, string $folder = ''): Asset
    {
        $folder = trim($folder === '/' ? '' : $folder, '/');

        // Saved under an extension that matches what the file really is (never .html, .php…),
        // and SVG pictures lose anything that could run.
        $extension = \App\Support\SafeUpload::extension($file, explode(',', self::MIMES));
        if ($extension === 'svg' && ! \App\Support\SafeUpload::cleanSvgFile($file->getRealPath())) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'uploads' => '“'.$file->getClientOriginalName().'” doesn’t look like a picture that can be shown safely, so it wasn’t added.',
            ]);
        }
        $name = $this->availableName($folder, pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.'.$extension);

        $this->disk()->putFileAs($folder, $file, $name);

        return $this->record(ltrim($folder.'/'.$name, '/'));
    }

    /**
     * night-market.jpg → night-market-2.jpg if the name is taken.
     */
    public function availableName(string $folder, string $original): string
    {
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $base = Str::slug(pathinfo($original, PATHINFO_FILENAME)) ?: 'file';
        $name = "{$base}.{$extension}";
        $n = 2;

        while ($this->disk()->exists(ltrim("{$folder}/{$name}", '/'))) {
            $name = "{$base}-{$n}.{$extension}";
            $n++;
        }

        return $name;
    }

    /**
     * Write (or refresh) the meta for a file that is on the disk.
     */
    public function record(string $path, array $keep = []): Asset
    {
        $disk = $this->disk();
        $meta = array_merge($keep, [
            'size' => $disk->size($path),
            'mime_type' => $disk->mimeType($path) ?: null,
            'last_modified' => $disk->lastModified($path),
        ]);

        if (str_starts_with((string) $meta['mime_type'], 'image/') && $meta['mime_type'] !== 'image/svg+xml') {
            $dimensions = @getimagesizefromstring($disk->get($path));
            if ($dimensions) {
                $meta['width'] = $dimensions[0];
                $meta['height'] = $dimensions[1];
            }
        }

        /** @var Asset $asset */
        $asset = Booklet::assets()->make(['container' => $this->container, 'path' => $path], $meta);

        return Booklet::assets()->save($asset);
    }
}
