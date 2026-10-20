<?php

namespace App\Booklet\Stores;

use App\Booklet\Schema\Definition;
use App\Booklet\Support\FrontMatter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps items as YAML (or Markdown with front matter) files, in Statamic's
 * folder layout, e.g. content/collections/posts/notes-from-the-market.md.
 *
 * Files are read once per request and indexed by key (Statamic calls this
 * index "the Stache"); writes update the index in place.
 */
class FileStore implements Store
{
    /** @var array<string, array>|null key => record (with '_path') */
    protected ?array $index = null;

    protected Filesystem $files;

    public function __construct(protected Definition $definition)
    {
        $this->files = new Filesystem;
    }

    public function directory(): string
    {
        $root = config("booklet.paths.{$this->definition->fileRoot}");

        return rtrim($root, '/').'/'.$this->definition->fileDir;
    }

    public function all(): array
    {
        return array_values(array_map(fn ($r) => Arr::except($r, '_path'), $this->index()));
    }

    public function find(array $key): ?array
    {
        $record = $this->index()[$this->definition->keyString($key)] ?? null;

        return $record ? Arr::except($record, '_path') : null;
    }

    public function where(array $filters): array
    {
        return array_values(array_filter($this->all(), function (array $record) use ($filters) {
            foreach ($filters as $key => $value) {
                $actual = array_key_exists($key, $record['attributes'])
                    ? $record['attributes'][$key]
                    : Arr::get($record['data'], $key);

                if (is_array($value) ? ! in_array($actual, $value) : $actual != $value) {
                    return false;
                }
            }

            return true;
        }));
    }

    public function save(array $attributes, array $data): void
    {
        $index = $this->index();
        $key = $this->definition->keyString($attributes);
        $relative = $this->definition->path($attributes);
        $path = $this->directory().'/'.$relative;

        // The path can change without the key changing (an entry's slug), so
        // remove the old file rather than leaving a stale copy behind.
        $previous = $index[$key]['_path'] ?? null;
        if ($previous && $previous !== $path && $this->files->exists($previous)) {
            $this->files->delete($previous);
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $this->serialize($attributes, $data));

        $now = now()->format('Y-m-d H:i:s');
        $this->index[$key] = [
            'attributes' => $attributes,
            'data' => $data,
            'updated_at' => $now,
            'created_at' => $index[$key]['created_at'] ?? $now,
            '_path' => $path,
        ];
    }

    public function delete(array $key): void
    {
        $string = $this->definition->keyString($key);
        $path = $this->index()[$string]['_path'] ?? null;

        if ($path && $this->files->exists($path)) {
            $this->files->delete($path);
        }

        unset($this->index[$string]);
    }

    public function truncate(): void
    {
        foreach ($this->index() as $record) {
            if ($this->files->exists($record['_path'])) {
                $this->files->delete($record['_path']);
            }
        }

        $this->index = [];
    }

    /**
     * Forget the in-memory index so the next read rescans the files.
     */
    public function refresh(): void
    {
        $this->index = null;
    }

    /** @var array<string, string> Files that couldn't be read this request: path => why. */
    public static array $unreadable = [];

    protected function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $this->index = [];
        $directory = $this->directory();

        if (! $this->files->isDirectory($directory)) {
            return $this->index;
        }

        foreach ($this->files->allFiles($directory, true) as $file) {
            if ($file->getExtension() !== $this->definition->fileExtension) {
                continue;
            }

            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $derived = $this->definition->parse($relative);

            if ($derived === null) {
                continue; // belongs to another kind of item sharing this folder
            }

            try {
                [$attributes, $data] = $this->unserialize($file->getContents());
            } catch (ParseException $e) {
                // One broken file (a typo, or unfinished merge marks) mustn't take the whole
                // site down: it's left out, noted, and the check-up names it.
                static::$unreadable[$file->getPathname()] = $e->getMessage();
                report($e);

                continue;
            }
            $attributes = array_merge($attributes, $derived);
            $modified = date('Y-m-d H:i:s', $file->getMTime());

            $this->index[$this->definition->keyString($attributes)] = [
                'attributes' => $attributes,
                'data' => $data,
                'updated_at' => $modified,
                'created_at' => $modified,
                '_path' => $file->getPathname(),
            ];
        }

        return $this->index;
    }

    protected function serialize(array $attributes, array $data): string
    {
        // Leave out what the path already says, and empty attributes.
        $stored = Arr::except($attributes, $this->definition->derivedFromPath($attributes));
        $stored = array_filter($stored, fn ($value) => $value !== null && $value !== []);

        if ($this->definition->fileNestKey) {
            return FrontMatter::yaml($stored + [$this->definition->fileNestKey => $data]);
        }

        if ($this->definition->fileExtension === 'md') {
            $body = is_string($data['content'] ?? null) ? $data['content'] : '';
            $matter = $stored + ($body === '' ? $data : Arr::except($data, 'content'));

            return FrontMatter::dump($matter, $body);
        }

        return FrontMatter::yaml($stored + $data);
    }

    /**
     * @return array{0: array, 1: array} [attributes, data]
     */
    protected function unserialize(string $contents): array
    {
        if ($this->definition->fileExtension === 'md') {
            [$values, $body] = FrontMatter::parse($contents);
            if (trim($body) !== '') {
                $values['content'] = rtrim($body, "\n");
            }
        } else {
            $values = Yaml::parse($contents) ?? [];
        }

        $values = is_array($values) ? $values : [];
        $attributes = Arr::only($values, $this->definition->columns);

        if ($this->definition->fileNestKey) {
            return [$attributes, (array) ($values[$this->definition->fileNestKey] ?? [])];
        }

        return [$attributes, Arr::except($values, $this->definition->columns)];
    }
}
