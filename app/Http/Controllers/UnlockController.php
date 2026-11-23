<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiException;
use App\Models\LocalAuth;
use App\Security\IdleSignOut;
use App\Services\CentralApiService;
use App\Services\LocalData;
use Illuminate\Http\Request;

/** Signed out after inactivity: sign in again (offline too), or as someone else. */
class UnlockController extends Controller
{
    public function show(IdleSignOut $idle, LocalData $local)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }
        if (! $auth->locked_at) {
            return redirect()->route('dashboard');
        }

        return view('auth.unlock', [
            'auth' => $auth,
            'minutes' => $idle->minutes(),
            'pending' => $local->pendingCount(),
            'lockedOut' => $idle->lockedOut(),
        ]);
    }

    /** The page's own idle timer, or the server's check, signs the person out. */
    public function lock(IdleSignOut $idle)
    {
        if ($auth = LocalAuth::current()) {
            $idle->lock($auth);
        }

        return redirect()->route('unlock');
    }

    public function unlock(Request $request, IdleSignOut $idle, CentralApiService $api)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }
        $password = (string) $request->validate(['password' => ['required', 'string']])['password'];

        if ($idle->lockedOut()) {
            return back()->withErrors(['password' => 'Too many wrong passwords. Wait '.IdleSignOut::LOCKOUT_MINUTES.' minutes, or sign in as someone else.']);
        }

        $offline = $idle->checkOffline($auth, $password);
        if ($offline === true) {
            return redirect()->intended(route('dashboard'));
        }
        if ($offline === false) {
            return back()->withErrors(['password' => 'The password is incorrect.']);
        }

        // Signed in before this version: the server checks the password once.
        try {
            $result = $api->login($auth->email, $password);
        } catch (CentralApiException $e) {
            return back()->withErrors(['password' => $e->isOffline()
                ? 'This computer cannot check your password yet: connect to the internet and sign in once. Your records stay on this computer.'
                : $e->getMessage()]);
        }
        if ((int) ($result['user']['id'] ?? 0) !== (int) $auth->remote_user_id) {
            $api->logout($result['token'] ?? '');

            return back()->withErrors(['password' => 'The password is incorrect.']);
        }
        $api->logout($auth->api_token);
        $auth->update(['api_token' => $result['token']]);
        $idle->signedInOnline($auth, $password);

        return redirect()->intended(route('dashboard'));
    }
}
