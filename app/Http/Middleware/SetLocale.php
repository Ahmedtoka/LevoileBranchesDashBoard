<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = session('locale', 'ar');
        if (! in_array($locale, ['ar', 'en'], true)) {
            $locale = 'ar';
        }
        App::setLocale($locale);

        return $next($request);
    }
}
