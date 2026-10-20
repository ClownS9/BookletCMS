<?php

namespace App\Booklet;

use Illuminate\Support\ServiceProvider;

class BookletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BookletManager::class);
        $this->app->scoped(Site::class);
    }
}
