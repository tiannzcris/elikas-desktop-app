@extends('layouts.app')

@section('title', 'Private houses')
@section('nav-ec-board', 'active')

@section('content')
    <a href="{{ route('ec-board.index') }}" class="back-link">
        <i class="ti ti-arrow-left" style="font-size: 14px;" aria-hidden="true"></i> EC Board
    </a>

    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Private houses</h1>
            <p class="page-subtitle">Families of Barangay {{ $currentUser->barangay_name }} staying with relatives or friends, outside the centers</p>
        </div>
        @if ($event && ! $event->isClosed())
            <div class="page-actions">
                <a href="{{ route('private-houses.create') }}" class="btn btn-primary">
                    <i class="ti ti-home-plus" style="font-size: 16px;" aria-hidden="true"></i> Add family
                </a>
            </div>
        @endif
    </div>

    @if (! $event)
        <div class="callout callout-info mb-6" role="status" data-no-open-event>{{ \App\Models\EvacuationEvent::NO_OPEN_EVENT }}</div>
    @else
        <div class="stat-strip grid-cols-2 lg:grid-cols-3 mb-6">
            <div class="stat">
                <p class="stat-label">Families</p>
                <p class="stat-value">{{ number_format($summary['family_count']) }}</p>
            </div>
            <div class="stat">
                <p class="stat-label">People</p>
                <p class="stat-value">{{ number_format($summary['person_count']) }}</p>
            </div>
            <a href="{{ route('sync.index') }}" class="stat {{ $summary['waiting'] > 0 ? 'stat-pending' : '' }}">
                <p class="stat-label">Waiting to sync</p>
                <p class="stat-value">{{ number_format($summary['waiting']) }}</p>
            </a>
        </div>

        <section class="card" aria-labelledby="ph-title">
            <div class="flex flex-wrap items-center justify-between gap-2 px-6 py-4 border-b border-gray-100">
                <h2 id="ph-title" class="card-title">{{ $event->name }}</h2>
                <span class="text-sm text-gray-600" data-as-of>As of {{ $summary['fetched_at'] ? \App\Support\Format::when($summary['fetched_at']) : 'never fetched' }}</span>
            </div>
            @if ($summary['families']->isEmpty())
                <x-empty icon="ti-home" title="No families in private houses yet." text="Add a family staying with relatives or friends instead of a center.">
                    @unless ($event->isClosed())
                        <a href="{{ route('private-houses.create') }}" class="btn btn-secondary"><i class="ti ti-home-plus" aria-hidden="true"></i> Add family</a>
                    @endunless
                </x-empty>
            @else
                <table class="data-table">
                    <thead><tr><th>Family</th><th>Staying with</th><th class="num">People</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($summary['families'] as $family)
                            <tr>
                                <td class="font-medium text-gray-900">{{ $family['label'] }}</td>
                                <td>{{ $family['host'] }}</td>
                                <td class="num">{{ number_format($family['persons']) }}</td>
                                <td>
                                    @if ($family['waiting'])
                                        <span class="badge badge-warning">Waiting to sync</span>
                                    @elseif ($family['incomplete'])
                                        <span class="badge badge-neutral">Details pending</span>
                                    @else
                                        <span class="badge badge-success">Complete</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endif
@endsection
