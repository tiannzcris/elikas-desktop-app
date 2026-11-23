<?php

namespace App\Http\Middleware;

use App\Models\LocalAuth;
use App\Security\IdleSignOut;
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

        // Signed out after inactivity: nothing shows until they sign in again.
        $idle = app(IdleSignOut::class);
        $route = $request->route()?->getName();
        if (in_array($route, IdleSignOut::OPEN_ROUTES, true)) {
            return $next($request);
        }
        if (! $auth->locked_at && $idle->expired()) {
            $idle->lock($auth);
        }
        if ($auth->locked_at) {
            if ($request->expectsJson() || in_array($route, IdleSignOut::BACKGROUND_ROUTES, true)) {
                return response()->json(['message' => 'Signed out after inactivity.', 'signed_out' => true], 423);
            }
            if ($request->isMethod('get')) {
                redirect()->setIntendedUrl($request->fullUrl());
            }

            return redirect()->route('unlock');
        }
        if (! in_array($route, IdleSignOut::BACKGROUND_ROUTES, true)) {
            $idle->touch();
        }

        // On a temporary password: choose a new one before anything else.
        if ($auth->must_change_password && ! in_array($route, ['password.change', 'password.update', 'system-notices.feed', 'about'], true)) {
            if ($request->expectsJson() || in_array($route, IdleSignOut::BACKGROUND_ROUTES, true)) {
                return response()->json(['message' => 'Change your temporary password first.', 'password_change_required' => true], 403);
            }

            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
