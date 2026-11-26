@extends('layouts.app')

@section('title', 'Relief boxes')
@section('nav-relief', 'active')

@section('content')
    @php
        $f = $relief['figures'];
        $n = fn ($v) => number_format((int) $v);
        $roundLabel = $relief['round'] ? 'Round '.($relief['round']['round_number'] ?? '') : null;
        $stay = function (?array $s) {
            if (! $s) return null;
            if ($s['private_house'] ?? false) return 'Private house';
            return ($s['center']['name'] ?? null) ? $s['center']['name'].(($s['other_barangay'] ?? false) ? ' · '.$s['barangay']['name'] : '') : ($s['barangay']['name'] ?? null);
        };
    @endphp
    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Relief boxes</h1>
            <p class="page-subtitle flex flex-wrap items-center gap-2">
                Barangay {{ $currentUser->barangay_name }}
                @if ($event)<span aria-hidden="true">·</span> <x-event-name :event="$event" />@endif
                @if ($roundLabel)<span aria-hidden="true">·</span> {{ $roundLabel }}@endif
            </p>
        </div>
        <div class="page-actions">
            @if (count($relief['rounds']) > 1)
                <form method="GET" action="{{ route('relief.index') }}" class="flex items-center gap-2">
                    <label for="round" class="sr-only">Round</label>
                    <select id="round" name="round" class="input w-auto" onchange="this.form.submit()">
                        @foreach ($relief['rounds'] as $r)
                            <option value="{{ $r['id'] }}" @selected(($relief['round']['id'] ?? null) === $r['id'])>Round {{ $r['round_number'] }}</option>
                        @endforeach
                    </select>
                    <input type="hidden" name="order" value="{{ $order }}">
                </form>
            @endif
            <form method="POST" action="{{ route('relief.refresh') }}">
                @csrf
                <button type="submit" class="btn btn-secondary" data-online-only><i class="ti ti-refresh" aria-hidden="true"></i> Refresh</button>
            </form>
        </div>
    </div>

    @if ($lock)
        <x-lock-notice :message="$lock" class="mb-6" data-event-lock />
    @endif

    @if ($event && ! $relief['has_copy'])
        <div class="card"><x-empty icon="ti-cloud-download" title="Relief boxes are not on this device yet." text="Refresh while online; the list stays usable offline, and boxes can be given offline.">
            <form method="POST" action="{{ route('relief.refresh') }}">@csrf<button type="submit" class="btn btn-secondary" data-online-only><i class="ti ti-refresh" aria-hidden="true"></i> Refresh now</button></form>
        </x-empty></div>
    @elseif ($event && ! $relief['round'])
        <div class="card"><x-empty icon="ti-box" title="No relief round yet." text="CSWD opens a round and dispatches boxes to the barangay." compact /></div>
    @elseif ($event)
        <div class="stat-strip grid-cols-2 lg:grid-cols-4 mb-2">
            <div class="stat"><p class="stat-label">Need</p><p class="stat-value">{{ $n($f['need']) }}</p>
                @if ($relief['need_basis'] === 'registered')<p class="stat-note" data-need-basis>Based on the registered count, not yet validated</p>@elseif ($relief['need_basis'] === 'validated')<p class="stat-note">Based on the validated count</p>@endif
            </div>
            <div class="stat"><p class="stat-label">Received</p><p class="stat-value">{{ $n($f['received']) }}</p>@if (($f['in_transit'] ?? 0) > 0)<p class="stat-note">{{ $n($f['in_transit']) }} on the way</p>@endif</div>
            <div class="stat"><p class="stat-label">Given</p><p class="stat-value">{{ $n($f['given']) }}</p>@if ($relief['waiting'] > 0)<p class="stat-note">{{ \App\Support\Plural::of($relief['waiting'], 'given here', 'given here') }}, waiting to sync</p>@endif</div>
            <div class="stat {{ $f['left_to_give'] > 0 ? '' : 'stat-alert' }}"><p class="stat-label">Left to give</p><p class="stat-value" data-left-to-give>{{ $n($f['left_to_give']) }}</p></div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <p class="text-sm text-gray-700" data-as-of>List as of {{ \App\Support\Format::when($relief['fetched_at']) }}. Boxes given here show at once and go with the next sync. The priority is a suggestion; you may give out of order.</p>
            <div class="flex items-center gap-1 text-sm" role="group" aria-label="Order">
                <a href="{{ route('relief.index', ['order' => 'priority', 'round' => $relief['round']['id']]) }}" class="btn btn-sm {{ $order === 'priority' ? 'btn-secondary' : 'btn-ghost' }}">By priority</a>
                <a href="{{ route('relief.index', ['order' => 'name', 'round' => $relief['round']['id']]) }}" class="btn btn-sm {{ $order === 'name' ? 'btn-secondary' : 'btn-ghost' }}">By name</a>
            </div>
        </div>

        @foreach ($relief['puroks'] as $group)
            <section class="card mb-4" aria-label="{{ $group['purok']['name'] ?? 'No purok' }}" data-relief-purok>
                <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-b border-gray-100">
                    <h2 class="card-title">{{ $group['purok']['name'] ?? 'No purok' }}</h2>
                    <span class="text-sm text-gray-600">{{ $n($group['given']) }} of {{ \App\Support\Plural::of($group['families']->count(), 'family', 'families') }} given</span>
                </div>
                <ul class="divide-y divide-gray-100">
                    @foreach ($group['families'] as $fam)
                        @php $name = $fam['head_name'] ?? $fam['name'] ?? 'Family #'.$fam['id']; @endphp
                        <li class="px-5 py-2.5 flex flex-wrap items-center justify-between gap-3 {{ $fam['next_in_line'] ? 'bg-hydro-tint' : '' }}" data-relief-family="{{ $fam['id'] }}">
                            <div class="min-w-0">
                                <p class="font-semibold text-sm text-gray-900 flex flex-wrap items-center gap-2">
                                    {{ $name }}
                                    @if ($fam['next_in_line'])<span class="badge badge-info" data-next-in-line>Next in line</span>@endif
                                    @if ($fam['priority_label'] ?? null)<span class="badge badge-warning" data-priority>Priority: {{ $fam['priority_label'] }}</span>@endif
                                    @if ($fam['from_barangay'] ?? null)<span class="badge badge-neutral" data-from-barangay>From {{ $fam['from_barangay']['name'] }}</span>@endif
                                </p>
                                <p class="text-xs text-gray-600">
                                    {{ \App\Support\Plural::of($fam['member_count'] ?? 0, 'person', 'persons') }}{{ ($s = $stay($fam['staying_in'] ?? null)) ? ' · '.$s : '' }}{{ ! empty($fam['host_name']) ? ' (with '.$fam['host_name'].')' : '' }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($fam['waiting_to_sync'])
                                    <span class="badge badge-warning" data-given-waiting>Given, waiting to sync</span>
                                @elseif ($fam['is_given'])
                                    <span class="badge badge-success">Given{{ ! empty($fam['given_at']) ? ' '.\App\Support\Format::when($fam['given_at']) : '' }}</span>
                                @elseif ($mayGive && ! $lock && $fam['can_give'])
                                    <form method="POST" action="{{ route('relief.give') }}"
                                        @if ($fam['higher_priority_waiting'] > 0) data-confirm-title="Give a box to this family?" data-confirm="{{ \App\Services\BoxRelief::outOfPriorityQuestion($fam['higher_priority_waiting']) }}" data-confirm-label="Give box" @endif>
                                        @csrf
                                        <input type="hidden" name="round_id" value="{{ $relief['round']['id'] }}">
                                        <input type="hidden" name="family_id" value="{{ $fam['id'] }}">
                                        <button type="submit" class="btn btn-sm {{ $fam['next_in_line'] ? 'btn-primary' : 'btn-secondary' }}" data-give-box>Give box</button>
                                    </form>
                                @elseif (! $fam['is_given'] && $f['left_to_give'] <= 0)
                                    <span class="text-sm text-gray-600">No box left</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        @if (! empty($relief['staying_elsewhere']))
            {{-- Their box comes from the barangay where they stay (no Give button here). --}}
            <section class="card mb-4" aria-labelledby="away-title" data-staying-elsewhere>
                <div class="px-5 py-3 border-b border-gray-100">
                    <h2 id="away-title" class="card-title">Staying in another barangay</h2>
                    <p class="text-sm text-gray-600">Your families in another barangay's center. That barangay gives their box; they still count under your barangay in reports.</p>
                </div>
                <ul class="divide-y divide-gray-100">
                    @foreach ($relief['staying_elsewhere'] as $away)
                        <li class="px-5 py-2.5 flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-sm text-gray-900">{{ $away['head_name'] ?? $away['name'] }}</p>
                                <p class="text-xs text-gray-600">{{ \App\Support\Plural::of($away['member_count'] ?? 0, 'person', 'persons') }}{{ ($s = $stay($away['staying_in'] ?? null)) ? ' · '.$s : '' }}</p>
                            </div>
                            <span class="badge {{ ($away['given'] ?? false) ? 'badge-success' : 'badge-neutral' }}">{{ ($away['given'] ?? false) ? 'Given by '.($away['gives_box']['name'] ?? 'the host') : ($away['gives_box']['name'] ?? 'The host').' gives the box' }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endif
@endsection
