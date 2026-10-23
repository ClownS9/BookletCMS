# Themes

How the public site looks, chosen and tweaked in **Settings › The cover**. Each
theme is a folder here. Booklet's own look needs no folder: it's always on the
shelf, as **Booklet**.

There are two kinds:

| | Cover pack | Theme with templates |
| --- | --- | --- |
| Brings | Styles, fonts, colours (papers), presets and its own settings | All of that, plus its own templates and, if it likes, scripts |
| Changes | How Booklet's pages look | The pages themselves |
| Safe from anyone? | Yes: Booklet reads its styles through before adding it | No: it's code. Only add ones from people you trust |
| Adding one asks for | Nothing more | Your password |

**Harbour**, a cover pack, comes with Booklet as an example.

## Making one

    php artisan booklet:make-theme lighthouse              # a cover pack
    php artisan booklet:make-theme lighthouse --templates  # starting from copies of Booklet's templates

Then pick it in Settings › The cover. To share it, zip the folder (or put it on
GitHub: people can add it from there, and look for updates later).

## The folder

    themes/lighthouse/
      theme.json                 what it is, and what it brings (below)
      README.md, LICENSE         optional
      assets/                    styles, fonts, pictures (and scripts, for a theme with templates)
        lighthouse.css
        preview.png              the picture on the shelf (16:10 works best)
      views/                     a theme with templates only (see Templates)
        components/site/layout.blade.php
        site/post.blade.php

Nothing else may be in it: no PHP besides templates, no files at the top other
than these. A .zip can have everything at the top, or in one folder (as GitHub's
downloads do).

## theme.json

```json
{
    "key": "lighthouse",
    "name": "Lighthouse",
    "about": "One line on how it looks.",
    "version": "1.0",
    "author": "You",
    "homepage": "https://example.com/lighthouse",
    "needs": "1.0",
    "preview": "preview.png",
    "css": ["lighthouse.css"],
    "fonts": {
        "display": { "name": "Fraunces", "google": "Fraunces:wght@400;600", "stack": "'Fraunces', Georgia, serif", "use": ["titles"] }
    },
    "papers": {
        "linen": { "name": "Linen", "paper": "#F7F4EE", "paper_2": "#ECE7DD", "rule": "#E0D9CC", "rule_2": "#CBC2B1", "ink": "#1D1B17" }
    },
    "options": {
        "wide": { "type": "toggle", "label": "Wider pages", "default": false, "class": "lighthouse-wide" },
        "shape": { "type": "select", "label": "Picture shape", "options": { "soft": "Soft", "round": "Round" }, "default": "soft", "class": "lighthouse-shape-{value}" },
        "link": { "type": "color", "label": "Link colour", "default": "#1F6F8B", "variable": "lighthouse-link" }
    },
    "presets": {
        "first": { "name": "Lighthouse", "about": "Where it starts.", "title_font": "display", "label_font": "inter", "accent": "#3B5BDB", "paper": "linen", "header": "classic", "home": "grid", "corners": "soft", "tilt": false, "options": { "wide": true } }
    }
}
```

| Key | What it does |
| --- | --- |
| `key` | Its folder's name: letters, numbers and dashes |
| `name`, `about`, `version`, `author`, `homepage` | How it shows on the shelf |
| `needs` | The Booklet version it needs, like `"1.0"` |
| `preview` | A picture in `assets/` for the shelf |
| `css` | Its stylesheets, from `assets/`, loaded after Booklet's own |
| `js` | Its scripts, from `assets/` (a theme with templates only) |
| `booklet_css` | `false` to leave out Booklet's stylesheet. Only for a theme with its own layout, and only if it styles everything itself (forms and comments included) |
| `fonts` | Fonts it adds to the font choices. `google`: a Google Fonts family as Google writes it; or leave it out and bring the font yourself, with `@font-face` in your CSS. `use`: `titles`, `labels` or both |
| `papers` | Paper colours it adds: `paper`, `paper_2` (a shade darker), `rule`, `rule_2` (lines), `ink` (the words). For light reading; dark reading keeps Booklet's |
| `options` | Its own settings in The cover: a `toggle` adds `class` to `<body>` when on; a `select` adds `class` with `{value}` filled in; a `color` sets the CSS variable `--{variable}` |
| `presets` | Ways to start. Each can set `title_font`, `label_font` (its own fonts by their handle, or Booklet's: `newsreader`, `fraunces`, `playfair`, `lora`, `dm-serif`, `inter`, `space`, `instrument`, `work`, `source`), `accent`, `paper` (its own or Booklet's: `warm`, `white`, `grey`, `blush`), `header` (`classic`, `centered`, `banner`), `home` (`feature`, `grid`, `list`), `corners` (`soft`, `sharp`), `tilt`, and `options`. Anything left out is Booklet's Notebook |

Anything Booklet can't use is left out and named in The cover, so you can see why.

## Styles

Every page's `<body>` has the class `.theme-{key}`, so start each rule with it.
The cover's choices are there to use:

- colours: `var(--accent)`, `var(--on-accent)` (text on the accent), `var(--paper)`, `var(--paper-2)`, `var(--rule)`, `var(--ink)`
- fonts: `var(--serif)` (titles and reading), `var(--sans)` (menus and labels)
- layouts: `.look-header--classic|centered|banner`, `.look-home--feature|grid|list`, `.look-corners--soft|sharp`, `.look-tilt` or `.look-flat`
- dark reading: `[data-theme="dark"]` on `<html>`

**A cover pack's styles are read before it's added.** They may use their own
files (`url(fonts/a.woff2)`, `url("../pictures/wave.svg")`) and pictures or fonts
written into the CSS (`data:`), but not:

- anything from another website (`url(https://…)`, `//…`, or an address in quotes);
- `@import` (list every file under `css` instead);
- the old ways to run script from CSS (`expression()`, `behavior:`, `-moz-binding`, `javascript:`).

SVG pictures are cleaned of anything that runs when the theme is added.

## Templates

A theme with templates puts Blade files in `views/`, with the same names as
Booklet's own in `resources/views/`. Booklet uses the theme's where there is one,
and its own everywhere else, so a theme can change just the post page.

Only these folders: `views/site/`, `views/components/site/` and `views/errors/`.

| Template | The page | It's given |
| --- | --- | --- |
| `components/site/layout.blade.php` | The frame around every page | `$slot`, and `title`, `description`, `image`, `imageAlt`, `type`, `robots` |
| `site/blog.blade.php` | The blog (and the home page, unless a page is chosen) | `$site`, `$featured`, `$posts`, `$paginator`, `$category`, `$categories` |
| `site/post.blade.php` | A post | `$site`, `$entry`, `$author`, `$category`, `$tags`, `$newer`, `$older`, `$related` |
| `site/page.blade.php` | A page | `$site`, `$entry`, `$template` |
| `site/archive.blade.php` | A category or tag | `$site`, `$term`, `$taxonomy`, `$paginator`, `$total` |
| `site/search.blade.php` | Search | `$site`, `$q`, `$type`, `$results`, `$needle` |
| `site/partials/card.blade.php` | A post in a list | `$entry`, `$site`, `$i` |
| `errors/404.blade.php` | Page not found | |

`$site` is `App\Booklet\Site`: `$site->url($entry)`, `$site->markdown($text)`,
`$site->image($entry)`, `$site->crop($entry, 'hero')`, `$site->excerpt($entry)`,
`$site->menu('header')` and more. Copy a template from `resources/views/` to start
(`--templates` does that for the usual ones).

### Pieces

Use these instead of writing them yourself, and whatever Booklet adds to them
later reaches your theme too:

| Piece | What it is |
| --- | --- |
| `<x-site.page :title :description :image :image-alt :type :robots body-class="">` | The page itself: the `<head>` (titles, share cards, search-engine data, fonts, the cover's colours, every stylesheet and script), the preview and proof bars, and what forms need at the end. **A layout wraps everything in this.** A `<x-slot:head>` adds to `<head>` |
| `<x-site.brand />` | The site's name and logo, linking home |
| `<x-site.menu />` | The header menu, with drop-downs, wide panels and seasonal menus. `handle="…"` for another |
| `<x-site.tools />` | Search, light or dark, and the menu button for phones |
| `<x-site.footer />` | The colophon, with the footer menu |
| `<x-site.comments :entry="$entry" />` | Comments, when they're open (never on proof copies or plain files) |
| `<x-site.subscribe place="post" />` | New posts by email |
| `<x-site.contact-form :entry="$entry" />` | The contact form |
| `<x-site.languages :entry="$entry" />` | The same post in other languages |
| `<x-site.portrait :user="$author" size="32" />` | Someone's picture |

A layout, at its smallest:

```blade
@props(['title' => null, 'description' => null, 'image' => null, 'imageAlt' => null, 'type' => 'website', 'robots' => null])
<x-site.page :title="$title" :description="$description" :image="$image" :image-alt="$imageAlt" :type="$type" :robots="$robots">
    <header class="masthead"><div class="wrap masthead__inner"><x-site.brand /><x-site.menu /><x-site.tools /></div></header>
    <main id="main" tabindex="-1">{{ $slot }}</main>
    <x-site.footer />
</x-site.page>
```

### When a template breaks

Readers never see it: the page is made again with Booklet's own templates, and
The cover and the Check-up say which template broke, where and why. Booklet's
**safe mode** (`BOOKLET_SAFE_MODE=true` in `.env`) wears every theme as a cover
pack: its styles, without its templates or scripts.

## Plain files

Publishing as plain files includes the theme: its stylesheets, and the fonts and
pictures they use, are copied with the pages.
