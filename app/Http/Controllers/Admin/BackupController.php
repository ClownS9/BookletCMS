<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Backups;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Downloading a backup, and uploading one to restore from (admins only).
 * Uploads use a plain form so big backups (with all the pictures) fit.
 */
class BackupController extends Controller
{
    public function download(Request $request, string $name)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return response()->download(Backups::path($name), basename($name), ['Content-Type' => 'application/zip']);
    }

    public function upload(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $request->validate(['backup' => ['required', 'file', 'max:2097152']], [], ['backup' => 'backup file']);

        try {
            $name = Backups::keepUpload($request->file('backup')->getRealPath());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.backups')->withErrors(['backup' => $e->getMessage()]);
        }

        return redirect()->route('admin.backups', ['restore' => $name]);
    }
}
