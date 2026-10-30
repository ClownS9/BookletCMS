<?php

// What runs on its own (php booklet schedule:run, every minute from cron).
// Booklet's commands themselves are classes in app/Console/Commands.

// Stacy's natural voice keeps recent audio so repeated words aren't paid for twice; clear out old clips.
\Illuminate\Support\Facades\Schedule::call(fn () => \App\Stacy\Speaker::tidy())->daily()->name('stacy-voice-tidy');
// Visit fingerprints are only kept for the day they're made.
\Illuminate\Support\Facades\Schedule::call(fn () => \App\Support\VisitStats::tidy())->daily()->name('visit-stats-tidy');

// Stacy's Monday letter, for people with "Weekly summary" on (at most once a week each).

\Illuminate\Support\Facades\Schedule::command('booklet:weekly-letter')
    ->weeklyOn(1, '8:55')
    ->timezone(rescue(function () {
        $tz = (string) app(\App\Booklet\Site::class)->setting('timezone', '');

        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : config('app.timezone');
    }, config('app.timezone'), false))
    ->name('weekly-letter')
    ->withoutOverlapping();

// The bin keeps things for 30 days, then lets them go.
\Illuminate\Support\Facades\Schedule::call(fn () => \App\Support\Bin::tidy())->daily()->name('bin-tidy');

// Backups: the whole book in one file.

\Illuminate\Support\Facades\Schedule::call(fn () => \App\Support\Backups::nightly())->dailyAt('3:10')->name('nightly-backup')->withoutOverlapping();

// Sized copies of pictures: made as they're first needed; these make them all now, or start afresh.

// Broken links: every link in published posts and pages, checked once a week.

\Illuminate\Support\Facades\Schedule::call(fn () => \App\Support\LinkCheck::run())->weeklyOn(0, '4:20')->name('link-check')->withoutOverlapping();

// Moving in from WordPress, for exports too big to bring in from the browser.

// New posts by email: sent a few minutes after they go live; people who never confirmed are forgotten after a month.
\Illuminate\Support\Facades\Schedule::call(fn () => \App\Support\Newsletter::sendDue())->everyFiveMinutes()->name('newsletter')->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::call(fn () => \App\Support\Newsletter::tidy())->daily()->name('newsletter-tidy');
\Illuminate\Support\Facades\Schedule::call(fn () => \App\Support\Inbox::tidy())->daily()->name('inbox-spam-tidy');

// Pages, posts and the blog page built on the page: scheduled ones (and changes) go live.
\Illuminate\Support\Facades\Schedule::call(fn () => app(\App\PageBuilder\PageBuilder::class)->settle())->everyFiveMinutes()->name('builder-scheduled')->withoutOverlapping();

// Jobs inserts ask for ('every' => ['daily' => …] in their insert.php).
rescue(fn () => app(\App\Inserts\InsertManager::class)->schedule(app(\Illuminate\Console\Scheduling\Schedule::class)), null, true);
\Illuminate\Support\Facades\Schedule::call(fn () => app(\App\Inserts\InsertManager::class)->fireScheduledGoneLive())->everyFiveMinutes()->name('inserts-scheduled-live');

// Adding and updating inserts (the same as Settings › Inserts does, for those who'd rather type).

// Newer versions of inserts added from a link: looked for once a day, shown on the shelf.
\Illuminate\Support\Facades\Schedule::call(fn () => app(\App\Inserts\InsertInstaller::class)->check())->dailyAt('5:40')->name('inserts-update-check')->withoutOverlapping();
// A new version of Booklet: looked for once a day (it shows on the contents page and in Settings › Updates).
\Illuminate\Support\Facades\Schedule::call(fn () => \App\Support\Updates::checkIfDue())->dailyAt('5:30')->name('booklet-update-check')->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::call(fn () => app(\App\Themes\ThemeInstaller::class)->check())->dailyAt('5:50')->name('themes-update-check')->withoutOverlapping();

// Version control: commits the book's changes once things have been quiet for a couple of minutes.
\Illuminate\Support\Facades\Schedule::command('booklet:git-commit --when-due')->everyMinute()->name('version-control')->withoutOverlapping(10)
    ->when(fn () => \App\Support\VersionControl::on());

// Plain-file publishing: made again (and sent) once things have been quiet for a couple of minutes.
\Illuminate\Support\Facades\Schedule::command('booklet:publish-static --when-due')->everyMinute()->name('static-site')->withoutOverlapping(20)
    ->when(fn () => \App\Support\StaticSite::settings()['auto']);

// Posts and pages with a date to come down: they do, on time.
\Illuminate\Support\Facades\Schedule::call(fn () => \App\Support\Takedowns::settle())->everyMinute()->name('takedowns')->withoutOverlapping();

// Webhooks: scheduled posts that went live, and calls that are due (or due again).
\Illuminate\Support\Facades\Schedule::call(fn () => \App\Support\Webhooks::settle())->everyMinute()->name('webhooks')->withoutOverlapping();

// The check-up: the scheduler leaves a mark every minute, so the book can tell it's running.
\Illuminate\Support\Facades\Schedule::call(fn () => \Illuminate\Support\Facades\Cache::forever(\App\Support\Health::BEAT, now()->timestamp))->everyMinute()->name('scheduler-beat');

