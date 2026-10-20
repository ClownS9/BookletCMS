<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use Illuminate\Http\Request;

/** The list of confirmed subscribers as a spreadsheet (CSV), for moving to a mailing service. */
class SubscribersExportController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless(in_array($request->user()?->level(), ['admin', 'editor'], true), 403);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'name', 'subscribed_on', 'emails_sent']);
            Subscriber::active()->orderBy('id')->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $s) {
                    // A leading = + - @ would run as a formula in a spreadsheet.
                    $safe = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : $v;
                    fputcsv($out, [$safe($s->email), $safe($s->name), $s->confirmed_at?->toDateString(), $s->emails_sent]);
                }
            });
            fclose($out);
        }, 'subscribers-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
