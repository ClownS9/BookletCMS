<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage drivers
    |--------------------------------------------------------------------------
    |
    | Like Statamic, every kind of content can live in the database or in flat
    | files, independently. "database" uses the tables from the migrations;
    | "file" uses YAML/Markdown files under the paths below. You can also put
    | the class name of your own App\Booklet\Stores\Store implementation here.
    |
    | Move existing content between drivers with:
    |   php artisan booklet:export   (database → files)
    |   php artisan booklet:import   (files → database)
    |
    */

    'default_driver' => env('BOOKLET_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Inserts
    |--------------------------------------------------------------------------
    |
    | Extras tucked into the book: each is a folder in inserts/ with an
    | insert.php (php artisan booklet:insert my-thing makes one). Switch them
    | on and off in Settings › Inserts. Safe mode starts with every insert off,
    | for when one is causing trouble.
    |
    */

    // 1.1: inserts can guard forms ('guard') and keep secret settings ('secret').
    // 1.2: inserts can list their public pages ('pages': the sitemap, plain files).
    // 1.3: inserts can draw the blog page ('blog').
    'version' => '1.3',

    'inserts_path' => env('BOOKLET_INSERTS_PATH', base_path('inserts')),

    'safe_mode' => (bool) env('BOOKLET_SAFE_MODE', false),

    // Seeding makes a throwaway admin (test@example.com) too: for demos only.
    'demo_user' => filter_var(env('BOOKLET_DEMO_USER', false), FILTER_VALIDATE_BOOL),

    // The site's timezone until one is chosen in Settings (APP_TIMEZONE in .env;
    // config/app.php's own "timezone" stays UTC, as Laravel ships it).
    'timezone' => env('APP_TIMEZONE'),

    // Adding inserts from Settings › Inserts (a .zip, a link, or a GitHub
    // repository). false: only by putting their folder in inserts/ by hand.
    'inserts_install' => (bool) env('BOOKLET_INSERTS_INSTALL', true),

    // Themes (Settings › The cover): cover packs (styles, fonts, colours) and
    // themes with their own templates, each a folder in themes/. Adding a
    // theme with templates is code, so it follows inserts_install too; and
    // safe mode wears every theme as a plain cover pack (no templates, no scripts).
    'themes_path' => env('BOOKLET_THEMES_PATH', base_path('themes')),

    // Only needed for inserts kept in private GitHub repositories (or if
    // GitHub says to slow down): a token that can read them.
    'github_token' => env('BOOKLET_GITHUB_TOKEN'),

    'drivers' => [
        'collections' => env('BOOKLET_COLLECTIONS_DRIVER'),
        'blueprints' => env('BOOKLET_BLUEPRINTS_DRIVER'),   // blueprints + fieldsets
        'entries' => env('BOOKLET_ENTRIES_DRIVER'),
        'navigations' => env('BOOKLET_NAVIGATIONS_DRIVER'),
        'trees' => env('BOOKLET_TREES_DRIVER'),             // navigation + page trees
        'taxonomies' => env('BOOKLET_TAXONOMIES_DRIVER'),
        'terms' => env('BOOKLET_TERMS_DRIVER'),
        'globals' => env('BOOKLET_GLOBALS_DRIVER'),         // global sets + their values
        'assets' => env('BOOKLET_ASSETS_DRIVER'),           // containers + file meta
        'revisions' => env('BOOKLET_REVISIONS_DRIVER'),
        'forms' => env('BOOKLET_FORMS_DRIVER'),             // forms + submissions
    ],

    /*
    |--------------------------------------------------------------------------
    | Database driver
    |--------------------------------------------------------------------------
    */

    'database' => [
        'connection' => env('BOOKLET_DB_CONNECTION'), // null = default connection
    ],

    /*
    |--------------------------------------------------------------------------
    | File driver paths
    |--------------------------------------------------------------------------
    |
    | Same layout as Statamic: content/ for content, resources/ for blueprints
    | and forms, storage/ for revisions and form submissions.
    |
    */

    'paths' => [
        'content' => base_path('content'),
        'resources' => resource_path(),
        'storage' => storage_path('booklet'),
        // Booklet's own working files: plain-file builds, API settings (tests use a folder of their own).
        'private' => storage_path('app/private'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sites
    |--------------------------------------------------------------------------
    |
    | Booklet starts with one site. Add more for multilingual content; entries,
    | terms, trees and globals are stored per site.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Version control (git)
    |--------------------------------------------------------------------------
    |
    | With content kept as files, Booklet can commit every change to git on its
    | own (switched on in Settings › Version control, or here with BOOKLET_GIT).
    | Only these folders are committed; pushing happens only when switched on.
    |
    */

    'git' => [
        'enabled' => env('BOOKLET_GIT'),            // null: decided in Settings
        'push' => env('BOOKLET_GIT_PUSH'),          // null: decided in Settings
        'path' => env('BOOKLET_GIT_PATH'),          // the repository; null: this project's folder
        'binary' => env('BOOKLET_GIT_BINARY', 'git'),
        'paths' => ['content', 'resources/blueprints', 'resources/fieldsets', 'resources/forms'],
        'delay' => 2,                               // minutes of quiet before committing
        'remote' => env('BOOKLET_GIT_REMOTE', 'origin'),
        'branch' => env('BOOKLET_GIT_BRANCH'),      // null: the current branch
        'committer' => [
            'name' => env('BOOKLET_GIT_NAME', 'Booklet'),
            'email' => env('BOOKLET_GIT_EMAIL', 'booklet@localhost'),
        ],
    ],

    'default_site' => 'default',

    'sites' => [
        'default' => [
            'name' => env('APP_NAME', 'Booklet'),
            'locale' => 'en_US',
            'url' => '/',
        ],
    ],

];
