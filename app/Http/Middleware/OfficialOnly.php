<?php

namespace App\Http\Middleware;

use App\Models\LocalAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registration, check-out and sync are the barangay official's work on
 * the desktop. CSWD reads Monitoring and Final counts here and does
 * everything else on the web.
 */
class OfficialOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! LocalAuth::current()?->isBarangayOfficial()) {
            return redirect()->route('dashboard')->withErrors(['role' => 'That page is for barangay officials.']);
        }

        return $next($request);
    }
}
