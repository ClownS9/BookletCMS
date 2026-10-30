<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // The end of installing without a terminal: before the tables exist, so no sessions, cookies
            // or rate limits (they're kept in the database). Its one-time code can't be guessed.
            \Illuminate\Support\Facades\Route::get('/install/finish', [\App\Http\Controllers\InstallController::class, 'finish'])
                ->name('install.finish');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Signed-in people who open /login go to their book; everyone else signs in first.
        $middleware->redirectUsersTo('/admin');
        $middleware->redirectGuestsTo('/login');

        // The reading beacon is sent without a form; it only ever adds one to a counter.
        // Mail apps' one-click Unsubscribe posts without a form token (RFC 8058); the long link is the proof.
        $middleware->validateCsrfTokens(except: ['read', 'unsubscribe/*', 'conversation/*/stop']);

        // While Booklet swaps its files for a new version, readers wait a moment; finishing the update still works.
        $middleware->preventRequestsDuringMaintenance(except: ['admin/updates/finish']);

        // Setup comes before sign in: a new book's /admin goes straight to setup, not to /login first.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\RedirectToSetup::class,
        );

        $middleware->web(append: [
            // A brand new book opens on First setup, not the public site.
            \App\Http\Middleware\RedirectToSetup::class,
            \App\Http\Middleware\ApplySiteSettings::class,
            // Readers see "back soon" while the book is being looked after (php booklet down).
            \App\Http\Middleware\ReadersAway::class,
            // The book in the language its reader chose (readers' pages speak the site's).
            \App\Http\Middleware\SpeakBookLanguage::class,
            \App\Http\Middleware\CountVisit::class,
            \App\Http\Middleware\SecurityHeaders::class,
            // Last, so visits are still counted and headers still added for kept pages.
            \App\Http\Middleware\CachePages::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
