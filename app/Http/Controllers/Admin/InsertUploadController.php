<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Inserts\InsertInstaller;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An insert's .zip from someone's computer: unpacked to one side and shown
 * on the shelf. Nothing is added until they've looked and said yes.
 */
class InsertUploadController extends Controller
{
    public function __invoke(Request $request, InsertInstaller $installer)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $back = redirect()->route('admin.settings.inserts', ['add' => 1]);

        if ($why = $installer->blocked()) {
            return $back->withErrors(['upload' => $why]);
        }
        $request->validate(
            ['insert' => ['required', 'file', 'max:'.(InsertInstaller::MAX_ZIP / 1024)]],
            ['insert.max' => 'It’s bigger than '.(InsertInstaller::MAX_ZIP / 1048576).' MB, which is more than an insert should be.'],
            ['insert' => 'insert file'],
        );

        $file = $request->file('insert');
        try {
            $token = $installer->stage($file->getRealPath(), ['type' => 'upload', 'file' => mb_substr($file->getClientOriginalName(), 0, 120)], $file->getClientOriginalName());
        } catch (RuntimeException $e) {
            return $back->withErrors(['upload' => $e->getMessage()]);
        }

        return redirect()->route('admin.settings.inserts', ['look' => $token]);
    }
}
