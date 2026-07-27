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
        ]);
    }
}
