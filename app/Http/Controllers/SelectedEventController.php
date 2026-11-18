<?php

namespace App\Http\Controllers;

use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Services\SelectedEvent;
use Illuminate\Http\Request;

/** The top-bar event switcher: which event every screen works on. */
class SelectedEventController extends Controller
{
    public function update(Request $request, SelectedEvent $selected)
    {
        $event = $request->filled('event') ? EvacuationEvent::findOrFail($request->integer('event')) : null;
        $selected->choose($event, LocalAuth::current());

        $working = $selected->get();

        return back()->with('status', $working
            ? "Now working on \"{$working->name}\"".($working->isClosed() ? '. It is closed, so it is read-only.' : '.')
            : 'No event is open.');
    }
}
