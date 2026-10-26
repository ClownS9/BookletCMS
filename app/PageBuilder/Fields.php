<?php

namespace App\PageBuilder;

use Illuminate\Support\HtmlString;

/**
 * Drawing one section's fields in its view. On the live page (in the
 * editor) each piece of words carries data-pb-field, so a click can select
 * it and typing changes it; an empty one shows what it's for. For readers,
 * empty pieces are left out.
 *
 *   {{ $f->text('title', 'h2', 'pb-title') }}
 *   {{ $f->words('lede', 'pb-lede') }}
 *   {{ $f->link('button', 'button button--ink') }}
 *   {{ $f->picture('picture', 'pb-picture', '(max-width: 760px) 100vw, 50vw') }}
 *   and in a list, each of $f->items('items') draws its own: $card->text('title', 'h3')
 */
class Fields
{
    /** path => ['url' => …, 'srcset' => …, 'alt' => …, 'focus' => …] for a picture in Media, or null. Set by PageBuilder. */
    public static $pictures = null;

    public function __construct(
        public readonly array $data,
        public readonly bool $live = false,
        protected string $prefix = '',
        protected array $spec = [],
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    public function filled(string $key): bool
    {
        $v = $this->get($key);

        return is_array($v) ? (trim((string) ($v['label'] ?? '')) !== '' || array_filter($v) !== []) : trim((string) $v) !== '';
    }

    /** Is there something to draw here (or, on the live page, a place to type it)? */
    public function has(string ...$keys): bool
    {
        if ($this->live) {
            return true;
        }
        foreach ($keys as $key) {
            // A button's words count only with somewhere to go.
            if (str_ends_with($key, '.label') && trim((string) $this->get(substr($key, 0, -6).'.url', '')) === '') {
                continue;
            }
            if ($this->filled($key)) {
                return true;
            }
        }

        return false;
    }

    protected function label(string $key): string
    {
        return (string) ($this->spec[$key]['label'] ?? ucfirst($key));
    }

    protected function editable(string $path, string $kind, string $placeholder): string
    {
        return ' data-pb-field="'.e($this->prefix.$path).'" data-pb-kind="'.$kind.'" data-pb-placeholder="'.e($placeholder).'"';
    }

    /** One line of words. */
    public function text(string $key, string $tag = 'p', string $class = ''): HtmlString
    {
        $value = trim((string) $this->get($key, ''));
        $tag = preg_match('/^(h[1-6]|p|span|div|strong|em|figcaption|summary|b|cite|small)$/', $tag) ? $tag : 'p';
        if ($value === '' && ! $this->live) {
            return new HtmlString('');
        }
        $class = trim($class.($value === '' ? ' pb-empty' : ''));

        return new HtmlString('<'.$tag.($class !== '' ? ' class="'.e($class).'"' : '')
            .($this->live ? $this->editable($key, 'text', $this->label($key)) : '').'>'.e($value).'</'.$tag.'>');
    }

    /** A few lines: paragraphs split by an empty line, kept as typed. */
    public function words(string $key, string $class = '', string $tag = 'div'): HtmlString
    {
        $value = trim((string) $this->get($key, ''));
        $tag = in_array($tag, ['div', 'p', 'blockquote'], true) ? $tag : 'div';
        if ($value === '' && ! $this->live) {
            return new HtmlString('');
        }
        $class = trim('pb-words '.$class.($value === '' ? ' pb-empty' : ''));

        return new HtmlString('<'.$tag.' class="'.e($class).'"'.($this->live ? $this->editable($key, 'words', $this->label($key)) : '').'>'.e($value).'</'.$tag.'>');
    }

    /** Lines, one each (what's in a plan; ways to reach you). */
    public function lines(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $this->get($key, '')))));
    }

    /** A button: its words typed on the page, its address set on the left. */
    public function link(string $key, string $class = 'button'): HtmlString
    {
        $label = trim((string) $this->get($key.'.label', ''));
        $url = trim((string) $this->get($key.'.url', ''));
        if (($label === '' || $url === '') && ! $this->live) {
            return new HtmlString(''); // no words, or nowhere to go: no button for readers
        }
        $class = trim($class.($label === '' ? ' pb-empty' : ''));
        $outside = (bool) preg_match('~^https?://~i', $url) && ! str_starts_with($url, url('/'));

        return new HtmlString('<a class="'.e($class).'" href="'.e($url !== '' ? $url : '#').'"'
            .($outside && ! $this->live ? ' rel="noopener"' : '')
            .($this->live ? $this->editable($key.'.label', 'text', $this->label($key)) : '').'>'.e($label).'</a>');
    }

    /** One picture from Media, at a sensible size. */
    public function picture(string $key, string $class = '', string $sizes = '(max-width: 760px) 100vw, 50vw', bool $eager = false): HtmlString
    {
        $path = (string) $this->get($key, '');
        $pic = $path !== '' ? self::pictureFor($path) : null;
        if (! $pic) {
            return new HtmlString($this->live
                ? '<button type="button" class="pb-pick pb-empty '.e($class).'" data-pb-pick="'.e($this->prefix.$key).'"><span>Choose a picture</span></button>'
                : '');
        }

        return new HtmlString('<img class="'.e($class).'" src="'.e($pic['url']).'" alt="'.e($pic['alt']).'"'
            .($pic['srcset'] !== '' ? ' srcset="'.e($pic['srcset']).'" sizes="'.e($sizes).'"' : '')
            .' style="object-position: '.e($pic['focus']).'"'
            .($eager ? ' fetchpriority="high"' : ' loading="lazy"').' decoding="async"'
            .($this->live ? ' data-pb-pick="'.e($this->prefix.$key).'"' : '').'>');
    }

    public static function pictureFor(string $path): ?array
    {
        return is_callable(self::$pictures) ? (self::$pictures)($path) : null;
    }

    /**
     * Each thing in a list field, as its own Fields.
     *
     * @return array<int, self>
     */
    public function items(string $key): array
    {
        $out = [];
        foreach (array_values(array_filter((array) $this->get($key, []), 'is_array')) as $i => $item) {
            $out[$i] = new self($item, $this->live, $this->prefix.$key.'.'.$i.'.', (array) ($this->spec[$key]['fields'] ?? []));
        }

        return $out;
    }

    /** On the live page: which list this is, so a click can select it. */
    public function itemAttrs(string $key, int $i): HtmlString
    {
        return new HtmlString($this->live ? ' data-pb-item="'.e($this->prefix.$key.'.'.$i).'"' : '');
    }
}
