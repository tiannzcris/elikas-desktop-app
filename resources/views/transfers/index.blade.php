@extends('layouts.app')

@section('title', 'Transfers')
@section('nav-transfers', 'active')

@section('content')
    @php
        $requests = collect($list['requests']);
        $pending = $requests->where('status', 'pending');
        $at = fn ($t) => \App\Support\Format::when($t);
        $statusTone = ['pending' => 'badge-warning', 'accepted' => 'badge-success', 'declined' => 'badge-neutral'];
    @endphp

    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Transfers</h1>
            <p class="page-subtitle">
                {{ $currentUser->isCityWide() ? 'Every request to move families between barangays' : 'Requests to and from Barangay '.$currentUser->barangay_name }}
                @if ($event) · {{ $event->optionLabel() }} @endif
            </p>
        </div>
        <div class="page-actions">
            @if ($currentUser->isCityWide() && $currentUser->can('evacuees.transfer'))
                <a href="{{ route('transfers.city-move') }}" class="btn btn-primary"><i class="ti ti-arrows-exchange" aria-hidden="true"></i> Move a family</a>
            @endif
            <x-row-menu label="More actions">
                <form method="POST" action="{{ route('transfers.refresh') }}">
                    @csrf
                    <button type="submit" class="menu-item" role="menuitem" data-online-only><i class="ti ti-refresh" aria-hidden="true"></i> Refresh</button>
                </form>
            </x-row-menu>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mb-6" data-as-of>
        <span class="text-sm text-gray-700">As of {{ $list['fetched_at'] ? \App\Support\Format::when($list['fetched_at']) : 'never fetched' }}</span>
        <span class="text-sm text-gray-600" data-offline-note hidden>Offline: Accept and Decline come back when the connection does.</span>
    </div>

    @if (! $event)
        <div class="card"><x-empty icon="ti-calendar-off" title="No event is open." text="{{ \App\Models\EvacuationEvent::NO_OPEN_EVENT }}" /></div>
    @elseif ($requests->isEmpty())
        <div class="card">
            <x-empty icon="ti-arrows-exchange" title="No transfer requests in this event." text="{{ $currentUser->isCityWide() ? 'Barangays ask here when a center is full.' : 'When a center is full, tick its families under Families here on the EC Board and choose Move.' }}">
                <form method="POST" action="{{ route('transfers.refresh') }}">@csrf<button type="submit" class="btn btn-secondary" data-online-only><i class="ti ti-refresh" aria-hidden="true"></i> Refresh</button></form>
            </x-empty>
        </div>
    @else
        <div class="stat-strip grid-cols-2 lg:grid-cols-3 mb-6">
            <div class="stat {{ $pending->isNotEmpty() ? 'stat-alert' : '' }}"><p class="stat-label">Waiting for an answer</p><p class="stat-value">{{ $pending->count() }}</p></div>
            <div class="stat"><p class="stat-label">Accepted</p><p class="stat-value">{{ $requests->where('status', 'accepted')->count() }}</p></div>
            <div class="stat"><p class="stat-label">Declined</p><p class="stat-value">{{ $requests->where('status', 'declined')->count() }}</p></div>
        </div>

        <div class="flex flex-col gap-4" data-transfer-list>
            @foreach ($requests as $row)
                @php
                    $summary = \App\Support\Plural::of($row['families'] ?? 0, 'family', 'families').' ('.\App\Support\Plural::of($row['persons'] ?? 0, 'person', 'people').')';
                    $route = ($row['from_center']['name'] ?? '?').' to '.($row['to_center']['name'] ?? '?');
                @endphp
                <article class="card card-body flex flex-col gap-3" data-transfer="{{ $row['id'] }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="card-title">{{ $summary }}</p>
                            <p class="text-sm text-gray-700">
                                {{ $row['from_center']['name'] ?? '' }} <span class="text-gray-500">({{ $row['from_barangay']['name'] ?? '' }})</span>
                                <i class="ti ti-arrow-right text-gray-500" aria-label="to"></i>
                                {{ $row['to_center']['name'] ?? '' }} <span class="text-gray-500">({{ $row['to_barangay']['name'] ?? '' }})</span>
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if (($row['direction'] ?? '') === 'incoming')<span class="badge badge-info">To your barangay</span>@endif
                            @if (($row['direction'] ?? '') === 'outgoing')<span class="badge badge-neutral">Sent by your barangay</span>@endif
                            @if (! empty($row['escalated_at']) && $row['status'] === 'pending')<span class="badge badge-danger">Waiting over 30 min: CSWD told</span>@endif
                            <span class="badge {{ $statusTone[$row['status']] ?? 'badge-neutral' }}">{{ ucfirst($row['status']) }}</span>
                        </div>
                    </div>

                    {{-- Privacy: only each family's head and number of members. --}}
                    <ul class="flex flex-wrap gap-2 text-sm text-gray-800">
                        @foreach ($row['family_list'] ?? [] as $f)
                            <li class="rounded-md bg-gray-50 px-2 py-1 ring-1 ring-gray-200">{{ $f['head'] ?? 'Family' }} · {{ \App\Support\Plural::of($f['members'] ?? 0, 'member', 'members') }}</li>
                        @endforeach
                    </ul>

                    <dl class="dl-grid lg:grid-cols-3">
                        <div><dt>Asked by</dt><dd data-requested-by>{{ \App\Services\Transfers::requestedBy($row) }} · {{ $at($row['requested_at'] ?? null) }}</dd></div>
                        @if (! empty($row['note']))<div><dt>Note</dt><dd>{{ $row['note'] }}</dd></div>@endif
                        @if ($row['status'] === 'pending')
                            <div><dt>Waiting</dt><dd>{{ \App\Support\Format::duration($row['waiting_minutes'] ?? 0) }}</dd></div>
                        @else
                            <div><dt>{{ ucfirst($row['status']) }} by</dt><dd>{{ $row['decided_by']['name'] ?? '—' }}{{ ($row['decided_as'] ?? '') === 'cswd' ? ' (CSWD)' : '' }} · {{ $at($row['decided_at'] ?? null) }}</dd></div>
                            @if (! empty($row['decline_reason']))<div><dt>Reason</dt><dd>{{ $row['decline_reason'] }}</dd></div>@endif
                        @endif
                    </dl>

                    @if ($row['status'] === 'pending' && ! empty($row['may_decide']))
                        <div class="flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4" data-decide-actions>
                            <form method="POST" action="{{ route('transfers.decide', $row['id']) }}"
                                data-confirm-title="Accept {{ $summary }}?" data-confirm="They move from {{ $route }} now." data-confirm-label="Accept">
                                @csrf
                                <input type="hidden" name="decision" value="accept">
                                <button type="submit" class="btn btn-primary" data-online-only>Accept</button>
                            </form>
                            <form method="POST" action="{{ route('transfers.decide', $row['id']) }}" class="flex flex-wrap items-end gap-2"
                                data-confirm-title="Decline {{ $summary }}?" data-confirm="Nothing moves; the sender chooses another center." data-confirm-label="Decline">
                                @csrf
                                <input type="hidden" name="decision" value="decline">
                                <div>
                                    <label class="label" for="reason-{{ $row['id'] }}">Reason for declining</label>
                                    <input id="reason-{{ $row['id'] }}" name="reason" class="input w-72" minlength="3" maxlength="500" required placeholder="e.g. Our hall is full too">
                                </div>
                                <button type="submit" class="btn btn-secondary" data-online-only>Decline</button>
                            </form>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
@endsection

@section('scripts')
    <script>
        // Offline: Accept and Decline are hidden (may_decide can be out of date).
        const syncDecide = () => {
            document.querySelectorAll('[data-decide-actions]').forEach((el) => { el.hidden = !navigator.onLine; });
            const note = document.querySelector('[data-offline-note]');
            if (note) note.hidden = navigator.onLine;
        };
        syncDecide();
        window.addEventListener('online', syncDecide);
        window.addEventListener('offline', syncDecide);
    </script>
@endsection
