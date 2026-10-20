<?php

namespace App\Http\Controllers\Admin;

use App\Booklet\Site;
use App\Http\Controllers\Controller;
use App\Support\PrintBook;
use Illuminate\Http\Request;

/**
 * The typeset book: every chosen entry in one long page, which the browser
 * then cuts into real pages (booklet/print.js) to print or save as a PDF.
 */
class PrintBookController extends Controller
{
    public function __invoke(Request $request, Site $site)
    {
        $o = PrintBook::normalise($request->query());
        $entries = PrintBook::entries($o);
        [$sizeName, $width, $height] = PrintBook::SIZES[$o['size']];

        return response()->view('print.book', [
            'o' => $o,
            'site' => $site,
            'entries' => $entries,
            'byline' => $o['byline'] ?: PrintBook::authorsOf($entries),
            'sizeName' => $sizeName,
            'width' => $width,
            'height' => $height,
            'years' => $entries->map(fn ($e) => $e->date()?->year)->filter()->unique()->sort()->values(),
            'back' => route('admin.print'),
        ])->header('X-Robots-Tag', 'noindex');
    }
}
