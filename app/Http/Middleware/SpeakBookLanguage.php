<?php

namespace App\Http\Middleware;

use App\Support\BookLanguage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The book (and its sign-in pages) in the language its reader chose; readers' pages as they are. */
class SpeakBookLanguage
{
    public function handle(Request $request, Closure $next): Response
    {
        if (BookLanguage::isBook($request)) {
            BookLanguage::use(BookLanguage::for($request));
        }

        return $next($request);
    }
}
