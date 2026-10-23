<?php

use App\Http\Controllers\Api\ContentController;
use Illuminate\Support\Facades\Route;

/*
| The read-only content API, at /api/v1 (registered in AppServiceProvider so
| bootstrap/app.php stays as it is). Turned on, and keys made, in
| Settings › Content API. GET only: nothing here changes the site.
*/

Route::get('/', [ContentController::class, 'index'])->name('index');
Route::get('/site', [ContentController::class, 'about'])->name('site');
Route::get('/collections', [ContentController::class, 'collections'])->name('collections');
Route::get('/collections/{collection}/entries', [ContentController::class, 'entries'])->where('collection', '[A-Za-z0-9_-]+')->name('entries');
Route::get('/entries/{id}', [ContentController::class, 'entry'])->where('id', '[A-Za-z0-9_-]+')->name('entry');
Route::get('/find', [ContentController::class, 'find'])->name('find');
Route::get('/menus/{handle}', [ContentController::class, 'menu'])->where('handle', '[A-Za-z0-9_-]+')->name('menu');
Route::get('/taxonomies/{taxonomy}/terms', [ContentController::class, 'terms'])->where('taxonomy', '[A-Za-z0-9_-]+')->name('terms');

// A browser on another site asks first (CORS); the gate answers.
Route::options('/{any?}', fn () => response()->noContent())->where('any', '.*')->name('preflight');
