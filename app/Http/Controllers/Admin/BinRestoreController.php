<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BinItem;
use App\Support\Bin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * "Undo" on the stamp: put something straight back from the bin.
 */
class BinRestoreController extends Controller
{
    public function __invoke(Request $request, BinItem $item): JsonResponse
    {
        abort_unless(Bin::canHandle($request->user(), $item), 403);

        try {
            $back = Bin::restore($item);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $back);
    }
}
