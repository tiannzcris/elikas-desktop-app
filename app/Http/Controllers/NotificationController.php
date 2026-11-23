<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiException;
use App\Models\AppState;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Services\SelectedEvent;
use App\Services\CentralApiService;
use App\Services\Transfers;
use Illuminate\Http\Request;

/**
 * The top-bar bell: the account's in-app notifications (GET /notifications),
 * kept for offline reading. Each one says its type, event and barangay
 * (docs/backend/apps-api-2026-11-24.md 7.4), which decides where it opens
 * in this app; anything else opens on the web from its href.
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
                'type' => $n['type'] ?? null,
                'open' => route('notifications.open', array_filter(['href' => $n['href'] ?? '', 'type' => $n['type'] ?? null, 'event' => $n['evacuation_event_id'] ?? null])),
                'in_app' => self::inApp($n['href'] ?? null, $n['type'] ?? null),
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
        $type = (string) $request->query('type', '');
        $auth = LocalAuth::current();

        // About an event: work on that event first.
        if ($request->filled('event') && ($event = EvacuationEvent::firstWhere('remote_id', $request->integer('event')))) {
            app(SelectedEvent::class)->choose($event, $auth);
        }

        if (in_array($type, ['registration_open', 'event_activated'], true)) {
            // Registration opened: bring the event's state from the server, then the EC Board.
            // (Offline it fails quietly: the board shows what this computer knows.)
            app(AuthController::class)->refreshReferenceDataAction($api);

            return redirect()->route($auth->isBarangayOfficial() ? 'ec-board.index' : 'monitoring.index');
        }
        if ($auth->isBarangayOfficial() && in_array($type, ['final_count_reminder', 'count_submitted_on_behalf'], true)) {
            try {
                app(\App\Services\BarangayFinalCount::class)->refresh($auth, EvacuationEvent::working());
            } catch (\Throwable $e) {
            }

            return redirect()->route('final-count.index');
        }
        if ($type === 'support_request_updated' && $auth->can('support.report')) {
            return redirect()->route('support.index');
        }
        if ($type === 'families_arriving' && $auth->isCityWide()) {
            return redirect()->route('monitoring.index');
        }

        if ($type === 'transfer_notification' || str_starts_with($href, '/transfer-requests')) {
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

    /** Types that open a screen of this app (others open on the web). */
    public const IN_APP_TYPES = ['registration_open', 'event_activated', 'final_count_reminder', 'count_submitted_on_behalf',
        'support_request_updated', 'families_arriving', 'transfer_notification'];

    private static function inApp(?string $href, ?string $type = null): bool
    {
        return in_array($type, self::IN_APP_TYPES, true) || ($href !== null && str_starts_with($href, '/transfer-requests'));
    }
}
