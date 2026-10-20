<?php

namespace App\Booklet\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads and writes Markdown files with a YAML front matter block, the format
 * Statamic uses for entries:
 *
 *   ---
 *   id: 7c1e…
 *   title: 'Notes from the night market'
 *   ---
 *   The body text…
 */
class FrontMatter
{
    /**
     * @return array{0: array, 1: string} [front matter, body]
     */
    public static function parse(string $contents): array
    {
        $contents = str_replace("\r\n", "\n", $contents);

        if (! str_starts_with($contents, "---\n")) {
            return [Yaml::parse($contents) ?? [], ''];
        }

        $end = strpos($contents, "\n---", 4);

        if ($end === false) {
            return [Yaml::parse(substr($contents, 4)) ?? [], ''];
        }

        $matter = Yaml::parse(substr($contents, 4, $end - 4)) ?? [];
        $body = ltrim(substr($contents, $end + 4), "\n");

        return [is_array($matter) ? $matter : [], $body];
    }

    public static function dump(array $matter, string $body = ''): string
    {
        $yaml = $matter === [] ? '' : static::yaml($matter);

        return "---\n{$yaml}---\n".($body === '' ? '' : rtrim($body, "\n")."\n");
    }

    public static function yaml(array $data): string
    {
        return Yaml::dump($data, 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }
}
