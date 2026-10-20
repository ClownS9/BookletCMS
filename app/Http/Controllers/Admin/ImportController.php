<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\WordPressImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Uploading a WordPress export to look inside (admins only). A plain form,
 * so big exports fit.
 */
class ImportController extends Controller
{
    public function upload(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $request->validate(['export' => ['required', 'file', 'max:'.(WordPressImport::MAX_BYTES / 1024)]], [], ['export' => 'export file']);

        try {
            $name = WordPressImport::keepUpload($request->file('export')->getRealPath());
        } catch (RuntimeException $e) {
            return (new RedirectResponse(route('admin.import')))->setSession(app('session.store'))->withErrors(['export' => $e->getMessage()]);
        }

        // Built directly (not through redirect()), so a Livewire component earlier in a long-running process can't swap it.
        return new RedirectResponse(route('admin.import', ['file' => $name]));
    }
}
