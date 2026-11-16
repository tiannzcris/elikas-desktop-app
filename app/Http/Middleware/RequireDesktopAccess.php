<?php

namespace App\Http\Middleware;

use App\Models\LocalAuth;
use App\Services\CentralApiService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every page except login, "Use the web" and the update screen needs a
 * signed-in user with operational access (CSWD or a barangay official).
 * An account without it (the administrator) is signed out here, its
 * token revoked when the server can be reached, and sent to "Use the web".
 */
class RequireDesktopAccess
{
    public const OPEN_ROUTES = ['login', 'login.submit', 'logout', 'use-web', 'system.update-required', 'system.update-required.run'];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->route()?->getName(), self::OPEN_ROUTES, true)) {
            return $next($request);
        }

        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        if (! $auth->hasOperationsAccess()) {
            app(CentralApiService::class)->logout($auth->api_token);
            LocalAuth::query()->delete();

            return redirect()->route('use-web');
        }

        return $next($request);
    }
}
