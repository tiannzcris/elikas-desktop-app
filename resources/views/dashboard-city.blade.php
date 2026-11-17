@extends('layouts.app')

@section('title', 'Dashboard')
@section('nav-dashboard', 'active')

@section('content')
    @php $n = fn ($v) => number_format((int) $v); @endphp

    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Dashboard</h1>
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

    @include('partials._as_of', ['at' => $generatedAt, 'webUrl' => app(\App\Services\CentralApiService::class)->webUrl()])

    @if (! $figures)
        <div class="card"><div class="empty-state">
            <i class="ti ti-cloud-download" aria-hidden="true"></i>
            <p>{{ $generatedAt ? 'No event is open.' : 'Not on this device yet. Refresh while online.' }}</p>
        </div></div>
    @else
        <div class="stat-strip grid-cols-2 lg:grid-cols-4">
            <a href="{{ route('monitoring.index') }}" class="stat">
                <p class="stat-label">Persons displaced</p>
                <p class="stat-value">{{ $n($figures['persons'] ?? 0) }}</p>
                <p class="stat-note">{{ \App\Support\Plural::of($figures['families'] ?? 0, 'family', 'families') }}</p>
            </a>
            <a href="{{ route('monitoring.index') }}#centers" class="stat">
                <p class="stat-label">Centers in use</p>
                <p class="stat-value">{{ $n($figures['centers_in_use'] ?? 0) }}</p>
            </a>
            <a href="{{ route('final-counts.index') }}" class="stat {{ $waitingValidation > 0 ? 'stat-alert' : '' }}">
                <p class="stat-label">Final counts to validate</p>
                <p class="stat-value">{{ $waitingValidation }}</p>
                <p class="stat-note">{{ $notSubmitted }} not submitted</p>
            </a>
            <a href="{{ route('monitoring.index') }}#requests" class="stat {{ $waitingForCswd > 0 ? 'stat-alert' : '' }}">
                <p class="stat-label">Waiting for CSWD</p>
                <p class="stat-value">{{ $waitingForCswd }}</p>
            </a>
        </div>
    @endif
@endsection
