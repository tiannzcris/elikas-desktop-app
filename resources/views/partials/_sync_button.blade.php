@php
    // Optional -- only passed from the EC Board page (see ec-board.blade.php)
    // so a sync triggered from there redirects back to that SAME center+
    // event afterward (refreshing its own pending counts/breakdown),
    // instead of always landing on Registered Families. Built from
    // explicit, validated route params server-side (FamilyController::
    // sync()'s own $returnTo resolution), never a raw redirect URL taken
    // from input -- no open-redirect surface here.
    $returnToCenterId = $returnToCenterId ?? null;
    $returnToEventId = $returnToEventId ?? null;
@endphp
{{-- Reused by Registered Families and the EC Board page -- both trigger
     the exact same families.sync route (it syncs pending families, EC
     Board entries, and sectoral/4Ps figures together in one run, see
     FamilyController::sync()). The offline guard is wired once, shared,
     in app.js's updateSyncButtons() -- reuses navigator.onLine, this
     app's one existing connectivity-detection signal (same one behind
     the header's Online/Offline badge), disabling the button and
     explaining why rather than letting a doomed request run. --}}
<div data-sync-control>
    <form method="POST" action="{{ route('families.sync') }}">
        @csrf
        @if ($returnToCenterId)
            <input type="hidden" name="return_to_center_id" value="{{ $returnToCenterId }}">
            <input type="hidden" name="return_to_event_id" value="{{ $returnToEventId }}">
        @endif
        <button type="submit" data-sync-button class="btn-modern flex items-center gap-1.5 bg-white border border-gray-200 hover:bg-gray-50 text-sm text-gray-700 px-4 py-2.5">
            <i class="ti ti-cloud-upload" style="font-size: 15px;" aria-hidden="true"></i> Sync now
        </button>
    </form>
    <p data-sync-offline-warning class="items-center gap-1 text-xs text-amber-600 mt-1" style="display: none;">
        <i class="ti ti-alert-triangle" style="font-size: 12px;" aria-hidden="true"></i> Sync requires an internet connection.
    </p>
</div>
