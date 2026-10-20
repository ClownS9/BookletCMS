<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\UploadedFile;

/**
 * Uploads that can't be turned against the site.
 *
 *  · The saved file always ends in an extension from the allowed list that
 *    matches what the file really is (so a picture renamed "page.html" can't
 *    be served as a web page).
 *  · SVG pictures are stripped of anything that runs: scripts, event
 *    handlers, javascript: links, embedded pages and outside references.
 */
class SafeUpload
{
    /** Extensions that are fine to keep as they were named, when the contents agree. */
    public const SAME_FAMILY = [
        'jpg' => ['jpg', 'jpeg'], 'jpeg' => ['jpg', 'jpeg'],
        'mov' => ['mov', 'qt'], 'm4a' => ['m4a', 'mp4', 'm4v'],
        'docx' => ['docx', 'zip'], 'xlsx' => ['xlsx', 'zip'], 'pptx' => ['pptx', 'zip'],
        'csv' => ['csv', 'txt'], 'txt' => ['txt', 'csv'],
    ];

    /**
     * The extension to save under: the person's own, if it's allowed and
     * matches the contents; otherwise what the contents say they are.
     *
     * @param  string[]  $allowed
     */
    public static function extension(UploadedFile $file, array $allowed): string
    {
        $allowed = array_map('strtolower', $allowed);
        $named = strtolower((string) $file->getClientOriginalExtension());
        $real = self::sniff($file->getRealPath()) ?? strtolower((string) ($file->guessExtension() ?: $file->extension()));

        if ($named === 'svg' && in_array('svg', $allowed, true) && self::looksLikeSvg($file->getRealPath())) {
            return 'svg';
        }
        if (in_array($named, $allowed, true) && ($named === $real || in_array($real, self::SAME_FAMILY[$named] ?? [], true))) {
            return $named;
        }
        if (in_array($real, $allowed, true)) {
            return $real;
        }

        // Neither the name nor the contents are on the list: plain text can't run anywhere.
        return 'txt';
    }

    /** The extension the file's bytes say it has (not what the browser or the name claims). */
    protected static function sniff(string|false $path): ?string
    {
        if (! $path || ! is_file($path) || ! class_exists(\finfo::class)) {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: null;

        return $mime ? (\Symfony\Component\Mime\MimeTypes::getDefault()->getExtensions($mime)[0] ?? null) : null;
    }

    /** Clean an SVG file where it is. False if it isn't a usable SVG. */
    public static function cleanSvgFile(string $path): bool
    {
        $clean = self::cleanSvg((string) @file_get_contents($path));
        if ($clean === null) {
            return false;
        }

        return file_put_contents($path, $clean) !== false;
    }

    /** The SVG with everything that could run taken out, or null if it can't be read. */
    public static function cleanSvg(string $svg): ?string
    {
        if (trim($svg) === '' || stripos($svg, '<svg') === false) {
            return null;
        }
        // No DOCTYPEs (entity tricks) and no processing instructions beyond the XML line.
        $svg = preg_replace('/<!DOCTYPE[^>]*(\[[^\]]*\])?\s*>/is', '', $svg);
        $svg = preg_replace('/<\?(?!xml\s)[^?]*\?>/i', '', (string) $svg);

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML((string) $svg, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $dom->documentElement || strtolower($dom->documentElement->localName) !== 'svg') {
            return null;
        }

        $xpath = new DOMXPath($dom);

        // Elements that run things or pull in other documents.
        foreach (['script', 'foreignObject', 'iframe', 'embed', 'object', 'handler', 'listener', 'set', 'animate', 'animateMotion', 'animateTransform', 'image', 'feImage', 'use'] as $name) {
            foreach (iterator_to_array($xpath->query('//*[local-name()="'.$name.'"]')) as $node) {
                if ($name === 'use' || $name === 'image' || $name === 'feImage') {
                    // These are fine pointing inside the picture (#id) or at embedded image data.
                    $href = self::href($node);
                    if ($href === '' || str_starts_with($href, '#') || preg_match('#^data:image/(png|jpe?g|gif|webp);#i', $href)) {
                        continue;
                    }
                }
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (iterator_to_array($xpath->query('//*')) as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = trim(preg_replace('/[\x00-\x20]+/', '', html_entity_decode($attribute->nodeValue ?? '')));

                $runs = str_starts_with($name, 'on')
                    || preg_match('/^(javascript|vbscript|data:text|data:application)/i', $value)
                    || ($name === 'style' && preg_match('/expression\s*\(|javascript:|url\s*\(\s*[\'"]?\s*(?!#|data:image)/i', $attribute->nodeValue ?? ''))
                    || (in_array($name, ['href', 'xlink:href', 'src'], true) && $value !== '' && ! str_starts_with($value, '#') && ! preg_match('#^data:image/(png|jpe?g|gif|webp);#i', $value));

                if ($runs) {
                    $element->removeAttributeNode($attribute);
                }
            }
        }

        // <style> blocks: no imports, no outside urls, no scripts.
        foreach (iterator_to_array($xpath->query('//*[local-name()="style"]')) as $style) {
            if (preg_match('/@import|javascript:|expression\s*\(|url\s*\(\s*[\'"]?\s*(?!#|data:image)/i', $style->textContent)) {
                $style->parentNode?->removeChild($style);
            }
        }

        return $dom->saveXML($dom->documentElement);
    }

    protected static function looksLikeSvg(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 4096);

        return stripos($head, '<svg') !== false;
    }

    protected static function href(DOMElement $node): string
    {
        return trim((string) ($node->getAttribute('href') ?: $node->getAttributeNS('http://www.w3.org/1999/xlink', 'href')));
    }
}
