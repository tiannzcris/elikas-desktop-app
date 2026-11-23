{{-- Notices from the administrator (GET /system-notices) and the server's
     maintenance message; nothing to mark as read. --}}
@foreach ($notices as $notice)
    <div class="flex items-start gap-2 border-b border-indigo-200 bg-indigo-50 px-6 py-2.5 text-sm text-indigo-950" role="status" data-system-notice="{{ $notice['id'] ?? '' }}">
        <i class="ti ti-speakerphone shrink-0 mt-0.5" style="font-size: 16px;" aria-hidden="true"></i>
        <span><span class="font-semibold">{{ $notice['title'] ?? 'Notice' }}</span>@if (! empty($notice['message'])) · {{ $notice['message'] }}@endif</span>
    </div>
@endforeach
@if ($maintenance = \App\Support\AccountState::maintenanceMessage())
    <div class="flex items-start gap-2 border-b border-amber-200 bg-amber-50 px-6 py-2.5 text-sm text-amber-950" role="status" data-maintenance-banner>
        <i class="ti ti-tool shrink-0 mt-0.5" style="font-size: 16px;" aria-hidden="true"></i>
        <span><span class="font-semibold">The server is under maintenance.</span> {{ $maintenance }} Your work stays on this computer and goes when it is back.</span>
    </div>
@endif
