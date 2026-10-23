<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Themes\ThemeInstaller;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A theme's .zip from someone's computer: unpacked to one side, checked,
 * and shown in The cover. Nothing is added until they've looked and said yes.
 */
class ThemeUploadController extends Controller
{
    public function __invoke(Request $request, ThemeInstaller $installer)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $back = redirect()->route('admin.settings.look', ['add' => 1]);

        if ($why = $installer->blocked()) {
            return $back->withErrors(['upload' => $why]);
        }
        $request->validate(
            ['theme' => ['required', 'file', 'max:'.(ThemeInstaller::MAX_ZIP / 1024)]],
            ['theme.max' => 'It’s bigger than '.(ThemeInstaller::MAX_ZIP / 1048576).' MB, which is more than a theme should be.'],
            ['theme' => 'theme file'],
        );

        $file = $request->file('theme');
        try {
            $token = $installer->stage($file->getRealPath(), ['type' => 'upload', 'file' => mb_substr($file->getClientOriginalName(), 0, 120)], $file->getClientOriginalName());
        } catch (RuntimeException $e) {
            return $back->withErrors(['upload' => $e->getMessage()]);
        }

        return redirect()->route('admin.settings.look', ['staged' => $token]);
    }
}
