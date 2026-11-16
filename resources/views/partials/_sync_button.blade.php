@php
    // From the EC Board: return there after a no-JavaScript sync.
    $returnToCenterId = $returnToCenterId ?? null;
    $returnToEventId = $returnToEventId ?? null;
    $primary = $primary ?? false;
@endphp
{{-- "Sync now": with JavaScript the page sends one record at a time
     (POST sync.step) and shows progress; without it, families.sync sends
     everything in one request. Disabled while the device is offline. --}}
<div data-sync-control>
    <form method="POST" action="{{ route('families.sync') }}" data-sync-form data-step-url="{{ route('sync.step') }}" data-retry-url="{{ route('sync.retry') }}">
        @csrf
        @if ($returnToCenterId)
            <input type="hidden" name="return_to_center_id" value="{{ $returnToCenterId }}">
            <input type="hidden" name="return_to_event_id" value="{{ $returnToEventId }}">
        @endif
        <button type="submit" data-sync-button class="btn {{ $primary ? 'btn-primary' : 'btn-secondary' }}">
            <i class="ti ti-cloud-upload" style="font-size: 15px;" aria-hidden="true"></i> Sync now
        </button>
    </form>
    <p data-sync-progress class="text-xs text-gray-700 mt-1" role="status" aria-live="polite"></p>
    <p data-sync-offline-warning class="items-center gap-1 text-xs text-amber-800 mt-1" style="display: none;">
        <i class="ti ti-alert-triangle" style="font-size: 12px;" aria-hidden="true"></i> Sync needs an internet connection.
    </p>
</div>
