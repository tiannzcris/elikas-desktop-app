{{-- People of other open events in the same center, in the server's words
     ("87 people here for Typhoon Drill"; "Full: 87 here for Typhoon Drill
     + 900 for this event"). Nothing when only this event has people there. --}}
@if ($center->occupancy_note)
    <p class="text-sm text-gray-700 mt-2 flex items-start gap-1.5" data-occupancy-note>
        <i class="ti ti-users shrink-0 mt-0.5" style="font-size: 14px;" aria-hidden="true"></i>
        <span>{{ $center->occupancy_note }}</span>
    </p>
@endif
