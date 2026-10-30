<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Settings › Email takes over from .env the first time anything sends mail.
        $this->app->afterResolving('mail.manager', fn () => \App\Support\MailSettings::apply());

        // Building pages, posts and the blog page of sections, on the page itself.
        $this->app->singleton(\App\PageBuilder\Builder::class, fn () => new \App\PageBuilder\Builder(require app_path('PageBuilder/kit.php')));
        $this->app->singleton(\App\PageBuilder\PageBuilder::class);

        // Inserts: extras tucked into the book (inserts/*/insert.php).
        $this->app->singleton(\App\Inserts\InsertManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($proxies = trim((string) config('booklet.trusted_proxies'))) {
            \Illuminate\Http\Middleware\TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $this->app->make(\App\Inserts\InsertManager::class)->boot();

        // Public pages wear the chosen theme (Settings › The cover); last in the web group, after the session.
        $this->app['router']->pushMiddlewareToGroup('web', \App\Http\Middleware\ApplyTheme::class);

        // The read-only content API (Settings › Content API), outside the web pages' middleware.
        if (! $this->app->routesAreCached()) {
            \Illuminate\Support\Facades\Route::middleware(\App\Http\Middleware\ContentApiAccess::class)
                ->prefix('api/v1')->name('api.')
                ->group(base_path('routes/api.php'));
        }

        // The scheduler and commands keep the site's time too (web pages set it per request).
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            rescue(fn () => \App\Http\Middleware\ApplySiteSettings::applyTimezone(), null, false);
        }

        // The password reset email, in the book's own words (Laravel's says "Reset Password Notification").
        \Illuminate\Auth\Notifications\ResetPassword::toMailUsing(function ($user, string $token) {
            $site = app(\App\Booklet\Site::class)->name();
            $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

            return (new \Illuminate\Notifications\Messages\MailMessage)
                ->subject('Choose a new password for '.$site)
                ->greeting('Hello '.\Illuminate\Support\Str::before(trim((string) $user->name), ' ').',')
                ->line('Someone (hopefully you) asked to choose a new password for '.$site.'.')
                ->action('Choose a new password', route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]))
                ->line('The link works for '.$minutes.' minutes. If you didn’t ask, ignore this email: your password stays as it is.')
                ->salutation($site);
        });

        // Faster public pages: forget the kept copies when something readers see changes.
        // (Listeners return nothing: a model listener that returns false stops the ones after it.)
        $flush = function (): void {
            \App\Support\PageCache::flush();
        };
        \Illuminate\Support\Facades\Event::listen('booklet.changed', function (string $what): void {
            \App\Support\Remember::forget();
            if ($what === 'entries') {
                \App\Support\Webhooks::entriesChanged(); // a scheduled post that just went live is caught first
            }
            \App\Support\PageCache::changed($what);
            if (! in_array($what, ['revisions', 'form_submissions'], true)) {
                rescue(fn () => \App\Support\StaticSite::changed(), null, false); // published as plain files: due again
            }
        });
        \App\Models\Comment::saved(function ($c) use ($flush): void {
            if ($c->status === 'approved' || $c->wasChanged('status')) {
                $flush();
            }
        });
        \App\Models\Comment::deleted($flush);
        // Webhooks that are due go out after each response (and from the scheduler).
        $this->app->terminating(function (): void {
            if (! $this->app->runningInConsole() || $this->app->runningUnitTests()) {
                rescue(fn () => \App\Support\Webhooks::settle(), null, true);
            }
        });
        \App\Models\Webhook::saved(fn () => \App\Support\Webhooks::forget());
        \App\Models\Webhook::deleted(fn () => \App\Support\Webhooks::forget());
        \App\Models\User::saved(function ($u) use ($flush): void {
            if ($u->wasRecentlyCreated || $u->wasChanged(['name', 'byline', 'bio', 'avatar', 'role'])) {
                $flush();
            }
        });
        \App\Models\User::deleted($flush);
        \App\Models\Redirect::saved(function ($r) use ($flush): void {
            if ($r->wasRecentlyCreated || $r->wasChanged(['from_path', 'to_path', 'status'])) {
                $flush();
            }
        });
        \App\Models\Redirect::deleted($flush);
        \App\Models\InsertRecord::saved($flush);   // an album, an event…
        \App\Models\InsertRecord::deleted($flush);
    }
}
