@extends('layouts.app')

@section('title', 'Dashboard')
@section('nav-dashboard', 'active')

@section('content')
    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Dashboard</h1>
            <p class="page-subtitle">Barangay {{ $currentUser->barangay_name }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('ec-board.index') }}" class="btn btn-primary">
                <i class="ti ti-building-community" style="font-size: 15px;" aria-hidden="true"></i> Open EC Board
            </a>
            <x-row-menu label="More actions">
                <form method="POST" action="{{ route('reference-data.refresh') }}">
                    @csrf
                    <button type="submit" class="menu-item" role="menuitem" data-online-only>
                        <i class="ti ti-refresh" aria-hidden="true"></i> Refresh reference data
                    </button>
                </form>
            </x-row-menu>
        </div>
    </div>

    @include('partials._event_state', ['event' => $event, 'what' => \App\Support\EventLock::REGISTERING, 'showOpen' => true, 'arriving' => true])

    <div class="stat-strip grid-cols-2 lg:grid-cols-4 mb-8">
        <div class="stat">
            <p class="stat-label">Current event</p>
            <p class="stat-value text-base leading-8">
                @if ($event)
                    <x-event-name :event="$event" />
                @else
                    None open
                @endif
            </p>
        </div>
        <a href="{{ route('sync.index') }}" class="stat {{ $waiting > 0 ? 'stat-pending' : '' }}">
            <p class="stat-label">Waiting to send</p>
            <p class="stat-value">{{ number_format($waiting) }}</p>
        </a>
        <a href="{{ route('sync.index') }}" class="stat {{ $attention > 0 ? 'stat-alert' : '' }}">
            <p class="stat-label">Needs attention</p>
            <p class="stat-value">{{ number_format($attention) }}</p>
        </a>
        <div class="stat">
            <p class="stat-label">Last sent</p>
            <p class="stat-value text-base leading-8">
                {{ $lastSyncAt ? \App\Support\Format::when($lastSyncAt) : 'Not yet' }}
            </p>
        </div>
    </div>

    <section aria-labelledby="centers-title">
        <h2 id="centers-title" class="section-title mb-4">Your centers</h2>
        @if ($centers->isEmpty())
            <div class="card"><x-empty icon="ti-building-community" title="No evacuation centers on this device yet." text="Refresh reference data while online to bring your barangay's centers here."><form method="POST" action="{{ route('reference-data.refresh') }}">@csrf<button type="submit" class="btn btn-secondary" data-online-only><i class="ti ti-refresh" aria-hidden="true"></i> Refresh reference data</button></form></x-empty></div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach ($centers as $row)
                    @include('ec-board._center_card', ['center' => $row['center'], 'pending' => $row['pending'], 'readiness' => $board['readiness'][$row['center']->remote_id] ?? null])
                @endforeach
            </div>
        @endif
    </section>

    @if ($board['has_copy'])
        <div class="grid gap-6 xl:grid-cols-2 mt-8">
            {{-- GET /dashboard/barangay todo[]: done on the web (the desktop has no center editing). --}}
            <section class="card card-body flex flex-col gap-3" aria-labelledby="todo-title" data-todo>
                <div class="card-header"><h2 id="todo-title" class="card-title">To do</h2><span class="text-sm text-gray-600">On the web</span></div>
                @forelse ($board['todo'] as $item)
                    <div class="flex flex-wrap items-start justify-between gap-3 border-t border-gray-100 pt-3 first:border-t-0 first:pt-0" data-todo-kind="{{ $item['kind'] }}">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900">{{ $item['text'] }}</p>
                            @if (! empty($item['detail']))<p class="text-sm text-gray-600">{{ $item['detail'] }}</p>@endif
                        </div>
                        <span class="text-sm text-gray-700 shrink-0">{{ $item['action'] ?? 'Open' }}: <span class="font-medium break-all">{{ $webUrl }}{{ $item['href'] ?? '' }}</span></span>
                    </div>
                @empty
                    <x-empty icon="ti-circle-check" title="Nothing to do for your centers." compact />
                @endforelse
            </section>
            <section class="card card-body flex flex-col gap-3" aria-labelledby="alerts-title" data-alerts>
                <div class="card-header"><h2 id="alerts-title" class="card-title">Latest alerts</h2></div>
                @forelse ($board['alerts'] as $alert)
                    @php $tone = ['mandatory' => 'badge-danger', 'advisory' => 'badge-warning', 'info' => 'badge-info'][$alert['severity'] ?? ''] ?? 'badge-neutral'; @endphp
                    <div class="flex flex-wrap items-start justify-between gap-2 border-t border-gray-100 pt-3 first:border-t-0 first:pt-0">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900">{{ $alert['title'] ?? '' }}</p>
                            <p class="text-sm text-gray-600">{{ \App\Support\Format::when($alert['date_sent'] ?? null) }}@if ($target = \App\Services\BarangayDashboard::target($alert)) · <span data-alert-target>{{ $target }}</span>@endif</p>
                        </div>
                        <span class="badge {{ $tone }}">{{ ['mandatory' => 'Emergency', 'advisory' => 'Advisory', 'info' => 'Info'][$alert['severity'] ?? ''] ?? \Illuminate\Support\Str::headline($alert['severity'] ?? '') }}</span>
                    </div>
                @empty
                    <x-empty icon="ti-bell-off" title="No alerts." compact />
                @endforelse
            </section>
        </div>
        <p class="text-sm text-gray-600 mt-3">As of {{ \App\Support\Format::when($board['fetched_at']) }}.</p>
    @endif
@endsection
