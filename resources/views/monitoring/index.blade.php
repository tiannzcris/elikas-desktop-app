@extends('layouts.app')

@section('title', 'Monitoring')
@section('nav-monitoring', 'active')

@section('content')
    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Monitoring</h1>
            @if ($event)
                <p class="page-subtitle flex items-center gap-2">
                    {{ $event['name'] }}
                    @if (! empty($event['is_demo']))
                        <span class="badge badge-warning" data-demo-badge>DEMO</span>
                    @endif
                </p>
            @endif
        </div>
        <div class="page-actions">
            <form method="POST" action="{{ route('monitoring.refresh') }}">
                @csrf
                <button type="submit" class="btn btn-primary" data-online-only>
                    <i class="ti ti-refresh" style="font-size: 15px;" aria-hidden="true"></i> Refresh
                </button>
            </form>
        </div>
    </div>

    @include('partials._as_of', ['at' => $generatedAt])

    @if (! $hasCopy)
        <div class="card"><x-empty icon="ti-cloud-download" title="Monitoring is not on this device yet." text="Refresh while online; the copy stays readable offline."><form method="POST" action="{{ route('monitoring.refresh') }}">@csrf<button type="submit" class="btn btn-secondary" data-online-only><i class="ti ti-refresh" aria-hidden="true"></i> Refresh now</button></form></x-empty></div>
    @elseif (! $event)
        <div class="card"><x-empty icon="ti-calendar-off" title="No event is open." text="{{ \App\Models\EvacuationEvent::NO_OPEN_EVENT }}" /></div>
    @else
        @php
            $overview = $sections['overview'] ?? [];
            $figures = $overview['figures'] ?? [];
            $centers = $sections['centers'] ?? [];
            $vulnerable = $sections['vulnerable'] ?? [];
            $requests = $sections['requests']['requests'] ?? [];
            $n = fn ($v) => number_format((int) $v);
        @endphp

        <div class="tabs mb-6" role="tablist" data-tabs>
            <button type="button" class="tab" role="tab" data-tab="overview">Overview</button>
            <button type="button" class="tab" role="tab" data-tab="centers">Centers</button>
            <button type="button" class="tab" role="tab" data-tab="vulnerable">Vulnerable</button>
            <button type="button" class="tab" role="tab" data-tab="requests">Waiting for CSWD ({{ count($requests) }})</button>
        </div>

        <section data-tab-panel="overview">
            <div class="stat-strip grid-cols-2 lg:grid-cols-4 mb-6">
                <div class="stat"><p class="stat-label">Persons</p><p class="stat-value">{{ $n($figures['persons'] ?? 0) }}</p><p class="stat-note">{{ \App\Support\Plural::of($figures['families'] ?? 0, 'family', 'families') }}</p></div>
                <div class="stat"><p class="stat-label">Inside centers</p><p class="stat-value">{{ $n($figures['inside'] ?? 0) }}</p><p class="stat-note">{{ $n($figures['outside'] ?? 0) }} outside</p></div>
                <div class="stat"><p class="stat-label">Arrived in the last hour</p><p class="stat-value">{{ $n($figures['arrived_last_hour'] ?? 0) }}</p></div>
                <div class="stat"><p class="stat-label">Centers in use</p><p class="stat-value">{{ $n($figures['centers_in_use'] ?? 0) }}</p></div>
            </div>
            <section class="card">
                <div class="card-body">
                    <div class="card-header"><h2 class="card-title">Needs attention</h2></div>
                    @forelse ($overview['attention'] ?? [] as $item)
                        <p class="flex items-start gap-2 py-2 border-t border-gray-100 first:border-t-0 text-sm text-gray-800">
                            <span class="badge {{ ($item['level'] ?? '') === 'danger' ? 'badge-danger' : 'badge-warning' }}">{{ ($item['level'] ?? '') === 'danger' ? 'Urgent' : 'Watch' }}</span>
                            {{ $item['text'] }}
                        </p>
                    @empty
                        <x-empty icon="ti-circle-check" title="Nothing needs attention." compact />
                    @endforelse
                </div>
            </section>
        </section>

        <section data-tab-panel="centers" hidden>
            <div class="stat-strip grid-cols-3 mb-6">
                <div class="stat"><p class="stat-label">In use</p><p class="stat-value">{{ $n($centers['in_use'] ?? 0) }}</p></div>
                <div class="stat"><p class="stat-label">Open centers</p><p class="stat-value">{{ $n($centers['total'] ?? 0) }}</p></div>
                <div class="stat {{ ($centers['capacity_not_set'] ?? 0) > 0 ? 'stat-alert' : '' }}"><p class="stat-label">In use, no capacity on record</p><p class="stat-value">{{ $n($centers['capacity_not_set'] ?? 0) }}</p></div>
            </div>
            <div class="card"><div class="card-body">
                <table class="data-table">
                    <thead><tr><th>Center</th><th>Barangay</th><th class="num">Inside</th><th class="num">Capacity</th><th class="num">Full</th></tr></thead>
                    <tbody>
                        @foreach ($centers['centers'] ?? [] as $c)
                            <tr>
                                <td class="font-medium text-gray-900">{{ $c['name'] }}</td>
                                <td>{{ $c['barangay']['name'] ?? '' }}</td>
                                <td class="num">{{ $n($c['inside']) }}</td>
                                <td class="num">{{ $c['capacity'] !== null ? $n($c['capacity']) : 'Not set' }}</td>
                                <td class="num">
                                    @if ($c['percent'] !== null)
                                        <span class="badge {{ $c['percent'] >= 100 ? 'badge-danger' : ($c['percent'] >= 90 ? 'badge-warning' : 'badge-neutral') }}">{{ $c['percent'] }}%</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div></div>
        </section>

        <section data-tab-panel="vulnerable" hidden>
            @php $totals = $vulnerable['totals'] ?? []; @endphp
            <div class="stat-strip grid-cols-2 lg:grid-cols-4 mb-6">
                <div class="stat"><p class="stat-label">Infants</p><p class="stat-value">{{ $n($totals['infants'] ?? 0) }}</p></div>
                <div class="stat"><p class="stat-label">Seniors</p><p class="stat-value">{{ $n($totals['seniors'] ?? 0) }}</p></div>
                <div class="stat"><p class="stat-label">Persons with disability</p><p class="stat-value">{{ $n($totals['pwd'] ?? 0) }}</p></div>
                <div class="stat"><p class="stat-label">Pregnant · Lactating</p><p class="stat-value">{{ $n($totals['pregnant'] ?? 0) }} · {{ $n($totals['lactating'] ?? 0) }}</p></div>
            </div>
            <div class="card"><div class="card-body">
                <table class="data-table">
                    <thead><tr><th>Barangay</th><th class="num">Persons</th><th class="num">Infants</th><th class="num">Seniors</th><th class="num">PWD</th><th class="num">Pregnant</th><th class="num">Lactating</th><th class="num">4Ps</th></tr></thead>
                    <tbody>
                        @foreach ($vulnerable['by_barangay'] ?? [] as $row)
                            <tr>
                                <td class="font-medium text-gray-900">{{ $row['barangay']['name'] ?? $row['name'] ?? '' }}</td>
                                @foreach (['persons', 'infants', 'seniors', 'pwd', 'pregnant', 'lactating', 'fourps'] as $key)
                                    <td class="num">{{ $n($row[$key] ?? 0) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div></div>
        </section>

        <section data-tab-panel="requests" hidden>
            <div class="card"><div class="card-body">
                @if (empty($requests))
                    <x-empty icon="ti-circle-check" title="Nothing is waiting for CSWD." compact />
                @else
                    <table class="data-table">
                        <thead><tr><th>Waiting</th><th>What</th><th>Where</th><th class="num">Waited</th></tr></thead>
                        <tbody>
                            @foreach ($requests as $r)
                                @php $minutes = (int) ($r['waited_minutes'] ?? 0); @endphp
                                <tr>
                                    <td><span class="badge {{ $minutes >= 120 ? 'badge-danger' : 'badge-warning' }}">{{ $r['kind_label'] }}</span></td>
                                    <td class="text-gray-900">{{ $r['what'] }}</td>
                                    <td>{{ $r['where'] }}</td>
                                    <td class="num">{{ $minutes >= 60 ? intdiv($minutes, 60).' h '.($minutes % 60).' min' : $minutes.' min' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div></div>
        </section>
    @endif
@endsection
