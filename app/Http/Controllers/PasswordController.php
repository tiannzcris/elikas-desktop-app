<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiException;
use App\Models\LocalAuth;
use App\Services\CentralApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Changing the password (POST /auth/password): required first on a
 * temporary password (an administrator made or reset the account), and
 * open to anyone from the account menu. Online only.
 */
class PasswordController extends Controller
{
    public const MIN_LENGTH = 10;

    public function show()
    {
        return view('auth.change-password', ['currentUser' => LocalAuth::current()]);
    }

    public function update(Request $request, CentralApiService $api)
    {
        $auth = LocalAuth::current();
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:'.self::MIN_LENGTH, 'confirmed', 'different:current_password'],
        ], [
            'password.min' => 'The new password needs at least '.self::MIN_LENGTH.' characters.',
            'password.confirmed' => 'The two new passwords are not the same.',
            'password.different' => 'The new password must differ from the current one.',
        ]);

        try {
            $body = $api->send('post', 'auth/password', $auth->api_token, [
                'current_password' => $validated['current_password'],
                'password' => $validated['password'],
                'password_confirmation' => $validated['password'],
            ], 15);
        } catch (CentralApiException $e) {
            if ($e->isOffline()) {
                return back()->withErrors(['password' => 'Needs a connection. Your password was not changed.']);
            }
            // 422 errors.current_password: the current password is wrong.
            $field = isset($e->errors()['current_password']) ? 'current_password' : 'password';

            return back()->withErrors([$field => $field === 'current_password' ? 'The current password is incorrect.' : $e->getMessage()]);
        }

        $user = $body['data']['user'] ?? $body['data'] ?? [];
        $auth->update([
            'must_change_password' => (bool) ($user['must_change_password'] ?? false),
            // Signing in again after inactivity uses the new password.
            'password_hash' => Hash::make($validated['password']),
        ]);

        return redirect()->route('dashboard')->with('status', 'Password changed. Your other sessions were signed out; this computer stays signed in.');
    }
}
