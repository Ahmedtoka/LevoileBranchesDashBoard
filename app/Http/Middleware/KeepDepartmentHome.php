<?php

namespace App\Http\Middleware;

use App\Support\DepartmentHome;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A CCTV / finance member opening any other page lands back on their department's summary (see DepartmentHome). */
class KeepDepartmentHome
{
    public function handle(Request $request, Closure $next): Response
    {
        $prefix = DepartmentHome::prefixFor($request->user());
        if ($prefix === null || DepartmentHome::allows($prefix, $request->route()?->getName())) {
            return $next($request);
        }

        abort_unless($request->isMethod('GET') || $request->isMethod('HEAD'), 403);

        return redirect()->route($prefix.'.summary');
    }
}
