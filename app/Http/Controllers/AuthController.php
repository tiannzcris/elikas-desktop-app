<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Services\CentralApiService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function showLogin()
    {
        // Already logged in on this device -- no need to show the login
        // screen again (which would require internet); straight to work.
        if (LocalAuth::current()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request, CentralApiService $api)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $result = $api->login($validated['email'], $validated['password']);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['login' => $e->getMessage()])->withInput(['email' => $validated['email']]);
        }

        // Only one row ever exists here -- whoever is currently logged in
        // on this device. A fresh login always replaces it.
        LocalAuth::query()->delete();
        LocalAuth::create([
            'remote_user_id' => $result['user']['id'],
            'name' => $result['user']['name'],
            'email' => $result['user']['email'],
            'role' => $result['user']['role'],
            'barangay_id' => $result['user']['barangay']['id'] ?? null,
            'barangay_name' => $result['user']['barangay']['name'] ?? null,
            'api_token' => $result['token'],
            'logged_in_at' => now(),
        ]);

        // We know we have internet right now (login just succeeded), so
        // pull current reference data immediately rather than making the
        // user do it as a separate manual step.
        try {
            $this->refreshReferenceData($api, $result['token']);
        } catch (\RuntimeException $e) {
            // Login itself still succeeded -- don't block on this failing,
            // the dashboard will just show whatever was cached before (or
            // nothing, on a genuinely first-ever login).
        }

        return redirect()->route('dashboard');
    }

    public function logout()
    {
        LocalAuth::query()->delete();

        return redirect()->route('login');
    }

    /**
     * Manual "Refresh reference data" action -- for when the device
     * regains internet sometime after the initial login, and the user
     * wants current barangays/events/centers without logging out and back in.
     */
    public function refreshReferenceDataAction(CentralApiService $api)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        try {
            $this->refreshReferenceData($api, $auth->api_token);

            return back()->with('status', 'Reference data refreshed successfully.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['refresh' => $e->getMessage()]);
        }
    }

    private function refreshReferenceData(CentralApiService $api, string $token): void
    {
        $data = $api->fetchReferenceData($token);

        foreach ($data['barangays'] as $b) {
            Barangay::updateOrCreate(['remote_id' => $b['id']], ['name' => $b['name']]);
        }

        foreach ($data['events'] as $e) {
            EvacuationEvent::updateOrCreate(['remote_id' => $e['id']], [
                'name' => $e['name'],
                'event_type' => $e['event_type'],
                'status' => $e['status'],
            ]);
        }

        foreach ($data['centers'] as $c) {
            EvacuationCenter::updateOrCreate(['remote_id' => $c['id']], [
                'barangay_remote_id' => $c['barangay_id'],
                'name' => $c['name'],
                'status' => $c['status'],
            ]);
        }
    }
}
