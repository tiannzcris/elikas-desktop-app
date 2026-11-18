<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiException;
use App\Models\AppState;
use App\Models\LocalAuth;
use App\Services\CentralApiService;
use App\Services\Transfers;
use Illuminate\Http\Request;

/**
 * The top-bar bell: the account's in-app notifications (GET /notifications),
 * kept for offline reading. A notification carries only its web href (not
 * the record's id): /transfer-requests opens this app's Transfers screen,
 * with the list loaded again.
 */
class NotificationController extends Controller
{
    /** JSON for the bell; offline it answers the last copy. */
    public function feed(CentralApiService $api)
    {
        try {
            $data = $api->notifications(LocalAuth::current()->api_token);
            AppState::put('notifications', $data + ['fetched_at' => now()->toIso8601String()]);
            $fresh = true;
        } catch (CentralApiException $e) {
            $data = AppState::get('notifications', ['unread' => 0, 'notifications' => []]);
            $fresh = false;
        }

        return response()->json([
            'unread' => (int) ($data['unread'] ?? 0),
            'fresh' => $fresh,
            'notifications' => collect($data['notifications'] ?? [])->take(10)->map(fn ($n) => [
                'title' => $n['title'] ?? '',
                'message' => $n['message'] ?? '',
                'read' => (bool) ($n['read'] ?? false),
                'at' => $n['created_at'] ?? null,
                'open' => route('notifications.open', ['href' => $n['href'] ?? '']),
                'in_app' => self::inApp($n['href'] ?? null),
            ])->values(),
        ]);
    }

    public function markRead(CentralApiService $api)
    {
        try {
            $api->markNotificationsRead(LocalAuth::current()->api_token);
        } catch (CentralApiException $e) {
            return response()->json(['ok' => false]);
        }

        return response()->json(['ok' => true]);
    }

    /** Opens what a notification points at, from its href. */
    public function open(Request $request, Transfers $transfers, CentralApiService $api)
    {
        $href = (string) $request->query('href', '');

        if (str_starts_with($href, '/transfer-requests')) {
            // The feed has no request id: load the list again.
            try {
                $transfers->refresh(LocalAuth::current());
            } catch (CentralApiException $e) {
                return redirect()->route('transfers.index')->withErrors(['refresh' => 'Needs a connection. Showing the list as of its last refresh.']);
            }

            return redirect()->route('transfers.index');
        }

        return back()->with('status', 'Open this on the web: '.$api->webUrl().$href);
    }

    private static function inApp(?string $href): bool
    {
        return $href !== null && str_starts_with($href, '/transfer-requests');
    }
}
