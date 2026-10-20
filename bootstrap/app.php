<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Signed-in people who open /login go to their book; everyone else signs in first.
        $middleware->redirectUsersTo('/admin');
        $middleware->redirectGuestsTo('/login');

        // The reading beacon is sent without a form; it only ever adds one to a counter.
        // Mail apps' one-click Unsubscribe posts without a form token (RFC 8058); the long link is the proof.
        $middleware->validateCsrfTokens(except: ['read', 'unsubscribe/*', 'conversation/*/stop']);

        // Setup comes before sign in: a new book's /admin goes straight to setup, not to /login first.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\RedirectToSetup::class,
        );

        $middleware->web(append: [
            // A brand new book opens on First setup, not the public site.
            \App\Http\Middleware\RedirectToSetup::class,
            \App\Http\Middleware\ApplySiteSettings::class,
            \App\Http\Middleware\CountVisit::class,
            \App\Http\Middleware\SecurityHeaders::class,
            // Last, so visits are still counted and headers still added for kept pages.
            \App\Http\Middleware\CachePages::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
