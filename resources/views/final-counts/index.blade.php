@extends('layouts.app')

@section('title', 'Final counts')
@section('nav-final-counts', 'active')

@section('content')
    @php
        $states = [
            'pending' => ['Waiting for validation', 'badge-warning'],
            'returned' => ['Returned', 'badge-danger'],
            'none' => ['Not submitted', 'badge-neutral'],
            'validated' => ['Validated', 'badge-success'],
        ];
        $n = fn ($v) => $v === null ? '' : number_format((int) $v);
    @endphp

    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Final counts</h1>
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
            <form method="POST" action="{{ route('final-counts.refresh') }}">
                @csrf
                <button type="submit" class="btn btn-primary" data-online-only>
                    <i class="ti ti-refresh" style="font-size: 15px;" aria-hidden="true"></i> Refresh
                </button>
            </form>
        </div>
    </div>

    @include('partials._as_of', ['at' => $snapshot?->fetched_at?->toIso8601String()])

    @if (! $snapshot)
        <div class="card"><div class="empty-state">
            <i class="ti ti-cloud-download" aria-hidden="true"></i>
            <p>Not on this device yet. Refresh while online.</p>
        </div></div>
    @elseif (! $event)
        <div class="card"><div class="empty-state">
            <i class="ti ti-circle-check" aria-hidden="true"></i>
            <p>No event is open.</p>
        </div></div>
    @else
        <div class="stat-strip grid-cols-2 lg:grid-cols-4 mb-6">
            @foreach ($states as $key => [$label, $tone])
                <div class="stat {{ $key === 'pending' && ($counts[$key] ?? 0) > 0 ? 'stat-alert' : '' }}">
                    <p class="stat-label">{{ $label }}</p>
                    <p class="stat-value">{{ $counts[$key] ?? 0 }}</p>
                </div>
            @endforeach
        </div>

        <div class="card"><div class="card-body">
            @if ($rows->isEmpty())
                <p class="text-sm text-gray-600">No barangay has families in this event yet.</p>
            @else
                <table class="data-table" data-final-counts>
                    <thead>
                        <tr>
                            <th>Barangay</th>
                            <th>Status</th>
                            <th class="num">Submitted families</th>
                            <th class="num">Persons</th>
                            <th class="num">Families now</th>
                            <th class="num">Validated need</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $submission = $row['current_submission'] ?? null;
                                [$label, $tone] = $states[$row['state']] ?? [$row['state'], 'badge-neutral'];
                                $changed = $submission && ($row['computed']['families'] ?? null) !== ($submission['families'] ?? null);
                            @endphp
                            <tr>
                                <td class="font-medium text-gray-900">
                                    {{ $row['barangay']['name'] ?? '' }}
                                    @if (empty($row['has_official_account']))
                                        <span class="block text-xs font-normal text-gray-600">No official account</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $tone }}">{{ $label }}</span>
                                    @if (! empty($submission['review_note']))
                                        <span class="block text-xs text-gray-600 mt-1">{{ $submission['review_note'] }}</span>
                                    @endif
                                </td>
                                <td class="num">{{ $n($submission['families'] ?? null) }}</td>
                                <td class="num">{{ $n($submission['persons'] ?? null) }}</td>
                                <td class="num">
                                    {{ $n($row['computed']['families'] ?? null) }}
                                    @if ($changed)
                                        <span class="block text-xs text-amber-800">changed since submitted</span>
                                    @endif
                                </td>
                                <td class="num">{{ $n($row['validated_need']['families'] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div></div>
    @endif
@endsection
