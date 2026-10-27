<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Updates;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The parts of updating Booklet that change its own files: they answer
 * with a plain redirect, since the files this request started with are
 * swapped while it runs. Admins only. (Getting ready happens on the page,
 * App\Livewire\Admin\Updates.)
 */
class UpdateController extends Controller
{
    public function apply(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        try {
            $token = Updates::apply($request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.settings.updates')->with('stamp', $e->getMessage());
        }

        return redirect()->route('admin.updates.finish', ['token' => $token]);
    }

    public function finish(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        try {
            $to = Updates::finish((string) $request->query('token', ''), $request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.settings.updates')->with('stamp', $e->getMessage());
        }

        return redirect()->route('admin.settings.updates')->with('stamp', __('Booklet is now :version.', ['version' => $to]));
    }

    public function undo(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        try {
            $back = Updates::undo($request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.settings.updates')->with('stamp', $e->getMessage());
        }

        return redirect()->route('admin.settings.updates')->with('stamp', __('Back to Booklet :version.', ['version' => $back]));
    }
}
