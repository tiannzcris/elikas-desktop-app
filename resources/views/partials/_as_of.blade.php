{{-- A read-only copy of a server page: when it was counted, a way to
     fetch it again, and where the work itself is done (the web). --}}
<div class="flex flex-wrap items-center gap-x-4 gap-y-2 mb-6" data-as-of>
    <span class="badge badge-neutral">Read-only</span>
    <span class="text-sm text-gray-700">
        As of
        @if ($at)
            <time datetime="{{ $at }}">{{ \Illuminate\Support\Carbon::parse($at)->timezone('Asia/Manila')->format('M j, Y g:i A') }}</time>
        @else
            never fetched
        @endif
    </span>
    <span class="text-sm text-gray-700">Validate on the web: <span class="font-medium text-gray-900 break-all">{{ $webUrl }}</span></span>
</div>
