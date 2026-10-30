# Inserts

Extras tucked into the book. Each insert is a folder here with an `insert.php`
that describes what it adds. Add them, switch them on and off, and update them in
**Settings › Inserts**.

**Gallery** (albums from Media), **Events** (a page at /events, calendar files, and
a list for posts) and **Guard** (a “prove you’re human” check on the site’s forms,
words and addresses to turn away, and a log) are inserts of their own, each in its
own repository: add them from Settings › Inserts › Add. (Copies of them live in
`tests/Fixtures/inserts/`, for Booklet’s tests only.)

## Making one

    php booklet insert reading-list

makes `inserts/reading-list/` with a working example. Then change it.

## What an insert.php can say

| Key | What it does |
| --- | --- |
| `name`, `about`, `version`, `author` | How it appears on the shelf (and what Stacy knows about it) |
| `sticker` | Its colour: coral, sun, mint, sky, lilac, rose, sand or ink |
| `needs` | The Booklet version it needs, like `'1.0'` |
| `settings` | Fields drawn on the shelf for you (`secret` needs Booklet 1.1): `text`, `textarea`, `number`, `toggle`, `select`, `email`, `url`, and `secret` (another service’s key: kept encrypted with `APP_KEY`, never sent to the browser, left out of backups) |
| `pages` | Its public pages, for the sitemap and plain files: `['/events']`, or a function giving `[['path' => '/offer', 'updated' => $date]]` (Booklet 1.2) |
| `home` | A home page: `fn (Insert $insert, Request $request) => view(...)`, or null to leave it as it is (Booklet 1.2) |
| `blog` | The blog page: `fn (Insert $insert, Request $request) => view(...)`, or null to leave it as Booklet draws it (Booklet 1.3) |
| `guard` | Guarding the site’s forms (see below; Booklet 1.1, so say `'needs' => '1.1'`) |
| `page` | Its own page in the admin book: `title`, `chapter` (Posts, Pages, Media, People, Comments…; leave out for its own), `who` (roles), `form` (starting values), `data` (a function giving the view its variables), `actions` (functions its buttons call) |
| `slips` | Pieces for writing: `'gallery' => fn ($words, $insert) => …` makes `[[gallery: …]]` work in posts and pages |
| `slip_help` | How to use each slip, in one line |
| `routes` | Pages on the site: `fn ($router, $insert) => $router->get('/events', …)->name('index')` (named `site.insert.{key}.index`) |
| `site` | Files the site loads while it's on: `['css' => [...], 'js' => [...]]`, from `assets/` |
| `when` | Reacting to `entry.published`, `comment.received`, `message.received`, `subscriber.confirmed` |
| `every` | Jobs run on their own: `everyFiveMinutes`, `hourly`, `daily`, `weekly` |
| `contents` | Cards on the Contents page (see below) |
| `stacy` | What it teaches Stacy (see below) |
| `on`, `remove` | Run when it's switched on, or removed with its data |

In its views (`views/*.blade.php`), an insert's page uses `wire:model="form.title"`
and `wire:click="act('save')"` (or `act('delete', {{ $id }})`); an action returns a
stamp message, or `['form' => …, 'editing' => …, 'stamp' => …]`.

`$insert` gives it: `setting('name')`, `records('albums')` (`create`, `all`, `find`,
`findBySlug`, `update`, `delete`, `reorder`, with no migration needed), `validate($form, $rules)`,
`view('name', [...])` and `asset('file.css')`. Heavier data can bring its own
`migrations/`, run when it's switched on.

## Cards on the Contents page

```php
'contents' => [
    'this-week' => [
        'title' => 'Coming up this week',
        'who' => ['admin', 'editor'],          // optional: defaults to who can open its page
        'show' => fn (Insert $insert, User $user) => [
            'count' => 2,                      // shown after the title
            'text' => 'A line, if a list doesn’t fit.',
            'items' => [['label' => 'Bake sale', 'meta' => 'Saturday, 10am', 'href' => '/admin/inserts/events?open=4']],
            'link' => ['label' => 'All events', 'href' => route('admin.insert', $insert->key)],
        ],
    ],
],
```

Return `null` (or no text and no items) when there's nothing to say, and the card
stays hidden: the Contents page should only show what's worth a look today. Up to
five items show. Stacy's daily brief includes them too. `?open={id}` on an insert's
page runs its `open` action, so a link can land on one record.

## Guarding forms

An insert can guard the comment form, the contact form, new posts by email, and
sign-in (`comment`, `contact`, `subscribe`, `login`, `reset`):

```php
'guard' => [
    'forms' => fn (Insert $insert) => ['comment', 'contact'],          // which (or a plain list)
    'widget' => fn (string $form, Insert $insert) => '<div class="my-box"></div>',  // HTML in the form
    'check' => function (string $form, array $answers, Request $request, string $token, Insert $insert) {
        return str_contains($answers['body'] ?? '', 'casino') ? 'Not here, thanks.' : null;
    },
],
```

`check` returns why it says no (shown under the form), or null to let it through.
`$answers` is what was typed; `$token` is what the widget handed back: from the page,
`Livewire.find(id).set('guardToken', token, false)` on the form's component (Guard's
`assets/guard.js` shows how), and listen for the `booklet-guard-reset` event to start
the widget again after each try. An insert that breaks here never stops a form: its
problem is noted on the shelf and the form goes ahead. Safe mode switches guards off
with every other insert.

## Telling search engines

A page an insert adds can describe what's on it for search engines (schema.org),
alongside what Booklet says about the site:

```php
\App\Support\StructuredData::add(['@type' => 'Event', 'name' => 'Bake sale', 'startDate' => '2026-11-07T10:00:00+08:00', …]);
```

Call it while the page is being made (in the route, before returning the view).
Events does this for every event coming up.

## Teaching Stacy

`'stacy'` lists skills. A **look-up** answers questions; a **change** is offered
first and only happens after the person presses Yes.

```php
'stacy' => [
    'upcoming' => [
        'about' => 'List the events coming up.',           // what Stacy reads to decide when to use it
        'label' => 'Tells you what’s coming up',            // what people see on the shelf
        'look' => fn (array $args, Insert $insert, User $user) => ['events' => …],
    ],
    'add' => [
        'about' => 'Propose adding an event.',
        'takes' => ['title' => ['type' => 'string'], 'date' => ['type' => 'string', 'about' => 'YYYY-MM-DD']],
        'needs' => ['title', 'date'],                       // the ones she must give
        'propose' => fn (array $args, Insert $insert, User $user) => [
            'summary' => 'Add “Bake sale” on Saturday.',     // shown with the Yes button
            'button' => 'Yes, add it',
            'data' => [...],                                // handed to 'do' after Yes
        ],
        'do' => fn (array $data, Insert $insert, User $user) => 'Done.',
    ],
],
```

- `takes` types: `string`, `integer`, `number`, `boolean`, `list` (of strings);
  `options` limits a value to a few choices.
- `who` says which roles get the skill. Look-ups default to everyone; changes to
  editors and admins.
- A look-up can return `'links' => [['label' => …, 'href' => …]]`, shown under her
  answer. `propose` returns a sentence instead of the array when it can't be done
  (she passes it on); validation problems from `$insert->validate()` are passed on too.
  `do` returns a sentence, or `['text' => …, 'link' => ['label' => …, 'href' => …]]`.
- Admins can switch every change from inserts off in **How Stacy behaves › Use inserts**.

## When something goes wrong

- An insert whose `insert.php` can't be read is **torn out**: kept off, with the error
  on the shelf, until someone fixes it and presses **Try again**. The rest of the book
  carries on.
- A slip that fails shows nothing (the page stays whole) and notes the problem on the shelf.
- `BOOKLET_SAFE_MODE=true` in `.env` starts the book with every insert off.

- If an update breaks it, **Undo update** on the shelf puts the copy before it back.

## Adding one

On the shelf, **Add an insert** takes any of:

- a GitHub repository: `someone/booklet-recipes` (or its github.com address)
- a link to a `.zip`
- a `.zip` from your computer

You see what it is (name, version, who made it, where from) before anything
changes, and it arrives switched off. Adding or updating asks for your password,
since an insert is code that runs with the whole site's keys: only add inserts from
people you trust. Admins only.

The same from the command line:

    php booklet insert-install someone/booklet-recipes
    php booklet insert-install ~/Downloads/recipes.zip
    php booklet insert-update          # bring in newer versions
    php booklet insert-undo recipes    # put the copy before the last update back

Inserts added from GitHub or a link are checked for newer versions every day; the
shelf shows **Update** when there is one. `BOOKLET_INSERTS_INSTALL=false` in `.env`
switches adding from the shelf off (inserts can still be put in this folder by hand).
For inserts in private GitHub repositories, set `BOOKLET_GITHUB_TOKEN`.

## Sharing one

In Booklet: pick the insert in Settings › Inserts and open **Share it with other
Booklet sites**, a step-by-step guide with a download button and the names filled in.
From the command line:

    php booklet share recipes

packs it into a .zip, checked the same way the shelf checks an insert being added.
No GitHub needed: send the .zip, and anyone can add it.

To let people add it by name and get updates, put it in a GitHub repository with
`insert.php` at the top, give the repository the topic **`booklet-insert`** (every
Booklet then finds it under **Add an insert › Find inserts on GitHub**), and make a **release** for each
version, tagged with the version (`1.2` or `v1.2`). Without releases, the newest
code is used.

The shelf reads what an insert is **without running it**, so keep these written out
plainly at the top of the array in `insert.php` (not worked out by code):
`'key'` (its folder name, optional), `'name'`, `'about'`, `'version'`, `'author'`,
`'needs'`. Every PHP file is checked for mistakes before an insert is added.
