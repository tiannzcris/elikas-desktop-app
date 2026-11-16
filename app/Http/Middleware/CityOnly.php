<?php

namespace App\Http\Middleware;

use App\Models\LocalAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** CSWD's read-only pages (Monitoring, Final counts). */
class CityOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! LocalAuth::current()?->isCityWide()) {
            return redirect()->route('dashboard')->withErrors(['role' => 'That page is for CSWD.']);
        }

        return $next($request);
    }
}
