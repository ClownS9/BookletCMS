<?php

use App\Http\Controllers\FeedController;
use App\Http\Controllers\SiteController;
use App\Http\Middleware\TouchLastSeen;
use App\Livewire\Admin;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Auth\Setup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// The public site -------------------------------------------------------------

Route::get('/', [SiteController::class, 'home'])->name('site.home');
Route::get('/search', [SiteController::class, 'search'])->name('site.search');
Route::get('/feed.xml', [FeedController::class, 'feed'])->name('site.feed');
Route::get('/sitemap.xml', [FeedController::class, 'sitemap'])->name('site.sitemap');
// What search engines may look at, and where the sitemap is (at the site's own address).
Route::get('/robots.txt', function () {
    $site = app(\App\Booklet\Site::class);

    return response(implode("\n", [
        'User-agent: *',
        'Disallow: /admin',
        'Disallow: /preview',
        'Disallow: /proof',
        '',
        'Sitemap: '.$site->absolute(route('site.sitemap')),
        '',
    ]), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
})->name('site.robots');

// New posts by email: confirming, and leaving (one click from mail apps too).
Route::get('/subscribe/confirm/{token}', [\App\Http\Controllers\SubscribeController::class, 'confirm'])->middleware('throttle:30,1')->name('site.subscribe.confirm');
Route::get('/unsubscribe/{token}', [\App\Http\Controllers\SubscribeController::class, 'leaving'])->middleware('throttle:30,1')->name('site.unsubscribe');
Route::post('/unsubscribe/{token}', [\App\Http\Controllers\SubscribeController::class, 'leave'])->middleware('throttle:30,1')->name('site.unsubscribe.leave');

// "Stop emails about this conversation" (from a reply email; signed).
Route::match(['get', 'post'], '/conversation/{comment}/stop', \App\Http\Controllers\ConversationController::class)->middleware('throttle:30,1')->name('site.conversation.stop');

// A share card (the picture shown when a link is shared) not drawn yet.
Route::get('/storage/'.\App\Support\ShareImage::FOLDER.'/{file}', \App\Http\Controllers\ShareImageController::class)
    ->where('file', '[\w-]+\.png')->middleware('throttle:120,1')->name('share.image');

// A sized copy of a picture not made yet (made copies are plain files the web server sends itself).
Route::get('/storage/'.\App\Support\Pictures::FOLDER.'/{width}/{path}', \App\Http\Controllers\PictureController::class)
    ->whereNumber('width')->where('path', '.+\.webp')
    ->middleware('throttle:240,1')->name('picture.sized');

// A named size of a picture ("card", "square"…) not cut yet.
Route::get('/storage/'.\App\Support\Crops::FOLDER.'/{size}/{spec}/{path}', \App\Http\Controllers\CropController::class)
    ->where('size', '[a-z][a-z0-9_]{0,30}')->where('spec', '\d+x\d+-\d+-\d+')->where('path', '.+\.webp')
    ->middleware('throttle:240,1')->name('picture.cropped');

// "Read most of the way": sent once per page by the site's script (no cookies, no personal data).
Route::post('/read', function (Request $request) {
    $path = (string) $request->input('path', '');
    if (! $request->user() && $path !== '' && strlen($path) < 300 && ! str_starts_with($path, '/proof/')
        && ! preg_match('/bot|crawl|spider|headless/i', (string) $request->userAgent())
        && $request->header('DNT') !== '1') {
        rescue(fn () => \App\Support\VisitStats::read($path), null, false);
    }

    return response()->noContent();
})->middleware('throttle:30,1')->name('site.read');

// Sign in -----------------------------------------------------------------

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
    Route::get('/setup', Setup::class)->name('setup');
    Route::get('/reset-password/{token}', ResetPassword::class)->name('password.reset');
});

Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::get('/preview/{entry}', [SiteController::class, 'preview'])->middleware('auth')->name('preview');

// Proof copies: a private link to a draft for someone without an account, and their notes.
Route::get('/proof/{secret}', [\App\Http\Controllers\ProofController::class, 'show'])
    ->where('secret', '[A-Za-z0-9]{20,100}')->middleware('throttle:60,1')->name('proof.show');
Route::post('/proof/{secret}/notes', [\App\Http\Controllers\ProofController::class, 'note'])
    ->where('secret', '[A-Za-z0-9]{20,100}')->middleware('throttle:10,1')->name('proof.note');

// The admin book ------------------------------------------------------------

Route::prefix('admin')->name('admin.')->middleware(['auth', TouchLastSeen::class])->group(function () {
    Route::get('/', Admin\Contents::class)->name('contents');

    Route::get('/collections', Admin\Collections::class)->name('collections');
    Route::get('/collections/{collection}', Admin\Entries::class)->name('entries');
    Route::get('/collections/{collection}/create', Admin\EntryEditor::class)->name('entries.create');
    Route::get('/collections/{collection}/entries/{entry}', Admin\EntryEditor::class)->name('entries.edit');
    Route::get('/collections/{collection}/entries/{entry}/editions', Admin\Editions::class)->name('entries.editions');

    Route::get('/taxonomies', Admin\Taxonomies::class)->name('taxonomies');
    Route::get('/menus', Admin\Menus::class)->name('menus');
    Route::get('/media', Admin\Media::class)->name('media');
    Route::get('/people', Admin\People::class)->name('people');
    // An admin trying the book out as another role: back to being themselves.
    Route::post('/view-as/stop', function (Request $request) {
        $request->session()->forget(\App\Models\User::VIEW_AS);

        return new \Illuminate\Http\RedirectResponse(route('admin.people'));
    })->name('view-as.stop');
    Route::get('/comments', Admin\Comments::class)->name('comments');
    Route::get('/inbox', Admin\Inbox::class)->name('inbox');
    Route::get('/settings/health', Admin\Health::class)->name('settings.health');
    Route::get('/settings/version-control', Admin\VersionControl::class)->name('settings.version-control');
    Route::get('/settings/plain-files', Admin\StaticPublishing::class)->name('settings.static');
    Route::get('/settings/cover', Admin\CoverDesigner::class)->name('settings.look');
    Route::post('/settings/cover/themes', \App\Http\Controllers\Admin\ThemeUploadController::class)->name('settings.themes.upload');
    // A theme or insert packed into a .zip to share (checked as another site would check it).
    Route::get('/share/{kind}/{key}', function (string $kind, string $key) {
        abort_unless(request()->user()->isAdmin(), 403);
        try {
            $packed = \App\Support\Sharing::pack($key, $kind);
        } catch (\RuntimeException $e) {
            return back()->with('stamp', \Illuminate\Support\Str::limit($e->getMessage(), 240));
        }

        return response()->download($packed['path'], $packed['file'])->deleteFileAfterSend();
    })->where(['kind' => 'theme|insert', 'key' => '[a-z][a-z0-9-]{0,59}'])->name('share.download');
    Route::get('/settings/content-api', Admin\ContentApiSettings::class)->name('settings.api');
    Route::get('/settings/plain-files/download', function () {
        abort_unless(request()->user()->isAdmin(), 403);
        try {
            $zip = \App\Support\StaticSite::zip();
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return response()->download($zip, \Illuminate\Support\Str::slug(app(\App\Booklet\Site::class)->name()).'-site-'.now()->format('Y-m-d').'.zip');
    })->name('settings.static.download');
    Route::get('/settings/inserts', Admin\Inserts::class)->name('settings.inserts');
    Route::post('/settings/inserts/upload', \App\Http\Controllers\Admin\InsertUploadController::class)->name('settings.inserts.upload');
    Route::get('/inserts/{insert}', Admin\InsertPage::class)->where('insert', '[a-z][a-z0-9-]*')->name('insert');
    Route::get('/settings', Admin\Settings::class)->name('settings');
    Route::get('/settings/email', Admin\EmailSettings::class)->name('settings.email');
    Route::get('/settings/stacy', Admin\StacySettings::class)->name('settings.stacy');
    Route::get('/settings/stacy/behaviour', Admin\StacyBehaviour::class)->name('settings.stacy.behaviour');
    Route::get('/account', Admin\Account::class)->name('account');
    Route::get('/activity', Admin\Activity::class)->name('activity');
    Route::get('/visits', Admin\Visits::class)->name('visits');
    Route::get('/bin', Admin\Bin::class)->name('bin');
    Route::post('/bin/{item}/restore', \App\Http\Controllers\Admin\BinRestoreController::class)->name('bin.restore');
    Route::get('/redirects', Admin\Redirects::class)->name('redirects');
    Route::get('/backups', Admin\Backups::class)->name('backups');
    Route::get('/backups/{name}/download', [\App\Http\Controllers\Admin\BackupController::class, 'download'])->where('name', '[A-Za-z0-9._-]+\.zip')->name('backups.download');
    Route::post('/backups/upload', [\App\Http\Controllers\Admin\BackupController::class, 'upload'])->name('backups.upload');
    Route::get('/import', Admin\ImportWordPress::class)->name('import');
    Route::get('/subscribers', Admin\Subscribers::class)->name('subscribers');
    Route::get('/subscribers/export', \App\Http\Controllers\Admin\SubscribersExportController::class)->name('subscribers.export');
    Route::post('/import/upload', [\App\Http\Controllers\Admin\ImportController::class, 'upload'])->name('import.upload');
    Route::get('/calendar', Admin\Calendar::class)->name('calendar');
    Route::get('/print', Admin\PrintBook::class)->name('print');
    Route::get('/print/book', \App\Http\Controllers\Admin\PrintBookController::class)->name('print.book');

    Route::get('/stacy', Admin\AskStacy::class)->name('stacy');
    Route::post('/stacy/voice', \App\Http\Controllers\Admin\StacyVoiceController::class)
        ->middleware('throttle:40,1')->name('stacy.voice');
});

// The blog index and every entry or term address, Statamic-style. Last, so
// the admin and sign-in routes above always win.
// Themes' own files (styles, fonts, pictures) from themes/{theme}/assets/.
Route::get('/themes/{theme}/assets/{path}', \App\Http\Controllers\ThemeAssetController::class)
    ->where(['theme' => '[a-z][a-z0-9-]*', 'path' => '[A-Za-z0-9._/-]+'])->name('themes.asset');

// Inserts' own files (CSS, scripts, pictures) from inserts/{insert}/assets/.
Route::get('/inserts/{insert}/assets/{path}', \App\Http\Controllers\InsertAssetController::class)
    ->where(['insert' => '[a-z][a-z0-9-]*', 'path' => '.+'])->name('inserts.asset');

// Building pages, posts and the blog page on the page itself (the editor talks to these).
Route::middleware('auth')->prefix('builder')->name('builder.')->group(function () {
    Route::post('/start/{target}', [\App\Http\Controllers\BuilderController::class, 'start'])->name('start');
    Route::get('/edit/{target}', [\App\Http\Controllers\BuilderController::class, 'edit'])->name('edit');
    Route::post('/edit/{target}', [\App\Http\Controllers\BuilderController::class, 'act'])->name('act');
    Route::get('/preview/{target}', [\App\Http\Controllers\BuilderController::class, 'preview'])->name('preview');
    Route::post('/here/{target}', [\App\Http\Controllers\BuilderController::class, 'ping'])->name('here');
});

Route::fallback([SiteController::class, 'show']);
