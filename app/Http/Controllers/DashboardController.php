<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;

class DashboardController extends Controller
{
    public function index()
    {
        $currentUser = LocalAuth::current();

        if (! $currentUser) {
            return redirect()->route('login');
        }

        return view('dashboard', [
            'currentUser' => $currentUser,
            'barangayCount' => Barangay::count(),
            'eventCount' => EvacuationEvent::count(),
            'centerCount' => EvacuationCenter::count(),
            'pendingSyncCount' => Family::whereNull('synced_at')->count(),
            'syncedCount' => Family::whereNotNull('synced_at')->count(),
            'primaryEcBoardCenter' => $this->primaryEcBoardCenter($currentUser),
        ]);
    }

    /**
     * EC Board is now the primary, fast-entry workflow (same design
     * decision already shipped on the web dashboard), so the Dashboard's
     * main call-to-action should go straight to it rather than making
     * staff pick a center first every time. There's no per-center staff
     * assignment in this app (confirmed when the Registered Families form
     * was scoped to barangay instead -- see families/_form.blade.php's own
     * note on that), so the only sensible deep-link is: if this staff
     * account's own barangay has EXACTLY ONE active center cached, that's
     * unambiguously "their" center -- go straight to its EC Board.
     * Otherwise (no barangay, or more than one center in it), there's no
     * single obvious target, so the action lands on the centers list
     * instead, same as clicking "Evacuation Centers" in the sidebar would.
     */
    private function primaryEcBoardCenter(LocalAuth $currentUser): ?EvacuationCenter
    {
        if (! $currentUser->barangay_id) {
            return null;
        }

        $centers = EvacuationCenter::where('barangay_remote_id', $currentUser->barangay_id)
            ->where('status', '!=', 'closed')
            ->get();

        return $centers->count() === 1 ? $centers->first() : null;
    }
}
