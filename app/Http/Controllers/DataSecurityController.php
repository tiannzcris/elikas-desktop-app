<?php

namespace App\Http\Controllers;

use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Security\IdleSignOut;
use App\Security\LocalDataEncryption;
use App\Security\LocalDataKey;
use App\Services\LocalData;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Data and security: how this computer protects the personal data it holds
 * (docs/local-data-protection.md), signing out after inactivity, and
 * removing closed events' data.
 */
class DataSecurityController extends Controller
{
    public function index(IdleSignOut $idle, LocalDataKey $key, LocalDataEncryption $encryption, LocalData $local)
    {
        $backups = collect(is_dir($encryption->backupsPath()) ? glob($encryption->backupsPath().DIRECTORY_SEPARATOR.'*.sqlite.enc') : [])
            ->map(fn ($path) => ['name' => basename($path), 'size' => filesize($path), 'at' => filemtime($path)])
            ->sortByDesc('at')->values();

        return view('data-security.index', [
            'currentUser' => LocalAuth::current(),
            'minutes' => $idle->minutes(),
            'options' => IdleSignOut::OPTIONS,
            'protection' => $key->protection(),
            'backups' => $backups,
            'closedWithData' => EvacuationEvent::where('status', 'closed')->get()
                ->filter(fn ($e) => $local->holdsDataFor($e))
                ->map(fn ($e) => ['event' => $e, 'unsent' => $local->unsentFor($e)])->values(),
            'waiting' => $local->pendingCount(),
        ]);
    }

    public function timeout(Request $request, IdleSignOut $idle)
    {
        $minutes = (int) $request->validate(['minutes' => ['required', 'integer', Rule::in(IdleSignOut::OPTIONS)]])['minutes'];
        $idle->setMinutes($minutes);

        return back()->with('status', "Signing out after {$minutes} minutes without activity.");
    }

    public function pruneClosed(LocalData $local)
    {
        $cleared = $local->pruneClosedEvents();

        return back()->with('status', $cleared
            ? 'Removed from this computer: '.implode(', ', $cleared).'. The server keeps everything.'
            : 'Nothing to remove: closed events with records still waiting to be sent are kept.');
    }
}
