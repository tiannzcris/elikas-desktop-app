<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiException;
use App\Models\AppState;
use App\Models\LocalAuth;
use App\Services\CentralApiService;
use App\Services\SyncService;
use Illuminate\Http\Request;

/**
 * The Sync page: what this device still has to send, what the server
 * refused, and the one-at-a-time sender the page drives (step()).
 */
class SyncController extends Controller
{
    public function index(SyncService $sync)
    {
        $label = fn (array $item) => [
            'key' => $item['key'],
            'kind' => $item['kind'],
            'model' => $item['model'],
            'label' => $sync->label($item),
            'place' => $sync->place($item),
            'blockedBy' => $sync->blockedBy($item),
        ];

        return view('sync.index', [
            'currentUser' => LocalAuth::current(),
            'waiting' => $sync->waiting()->map($label),
            'attention' => $sync->needsAttention()->map($label),
            'lastSyncAt' => AppState::get('last_sync_at'),
        ]);
    }

    /** Sends one record; the page calls this until it answers empty or stopped. */
    public function step(SyncService $sync)
    {
        return response()->json($sync->step(LocalAuth::current()));
    }

    /**
     * Can the central server be reached, and is the login still good? Also
     * brings the stored permissions up to date (GET /auth/me).
     */
    public function probe(CentralApiService $api)
    {
        try {
            AuthController::refreshIdentity($api, LocalAuth::current());

            return response()->json(['reachable' => true, 'auth' => true]);
        } catch (CentralApiException $e) {
            return response()->json([
                'reachable' => ! $e->isOffline(),
                'auth' => ! in_array($e->kind, [CentralApiException::UNAUTHENTICATED, CentralApiException::FORBIDDEN], true),
            ]);
        }
    }

    /** Puts refused records back in line before a run the user started. */
    public function retry(Request $request, SyncService $sync)
    {
        $sync->retryFailed($request->input('key'));

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }
}
