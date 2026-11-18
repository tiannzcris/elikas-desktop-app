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
        <div class="card"><x-empty icon="ti-cloud-download" title="Final counts are not on this device yet." text="Refresh while online; the copy stays readable offline."><form method="POST" action="{{ route('final-counts.refresh') }}">@csrf<button type="submit" class="btn btn-secondary" data-online-only><i class="ti ti-refresh" aria-hidden="true"></i> Refresh now</button></form></x-empty></div>
    @elseif (! $event)
        <div class="card"><x-empty icon="ti-calendar-off" title="No event is open." text="{{ \App\Models\EvacuationEvent::NO_OPEN_EVENT }}" /></div>
    @else
        @php $lateTotal = $snapshot->payload['late_entries_total'] ?? $rows->sum(fn ($r) => $r['late_entries']['total'] ?? 0); @endphp
        <div class="stat-strip grid-cols-2 lg:grid-cols-5 mb-4">
            @foreach ($states as $key => [$label, $tone])
                <div class="stat {{ $key === 'pending' && ($counts[$key] ?? 0) > 0 ? 'stat-alert' : '' }}">
                    <p class="stat-label">{{ $label }}</p>
                    <p class="stat-value">{{ $counts[$key] ?? 0 }}</p>
                </div>
            @endforeach
            <div class="stat {{ $lateTotal > 0 ? 'stat-pending' : '' }}" data-late-entries-total>
                <p class="stat-label">Late entries</p>
                <p class="stat-value">{{ $lateTotal }}</p>
                <p class="stat-note">not yet in a final count</p>
            </div>
        </div>
        <p class="text-sm text-gray-700 mb-6">A validated count never changes. Families added after a barangay's latest submission are late entries; they go into its next submission.</p>

        <div class="card"><div class="card-body">
            @if ($rows->isEmpty())
                <x-empty icon="ti-users" title="No barangay has families in this event yet." compact />
            @else
                <table class="data-table" data-final-counts>
                    <thead>
                        <tr>
                            <th>Barangay</th>
                            <th>Status</th>
                            <th class="num">Submitted families</th>
                            <th class="num">Persons</th>
                            <th class="num">Families now</th>
                            <th class="num">Late entries</th>
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
                                <td class="num" data-late-entries>
                                    @if (($row['late_entries']['total'] ?? 0) > 0)
                                        <span class="badge badge-warning">{{ \App\Support\Plural::of($row['late_entries']['families'] ?? $row['late_entries']['total'], 'family', 'families') }}</span>
                                        @if (! empty($row['late_entries']['removed_families']))
                                            <span class="block text-xs text-gray-600">{{ $row['late_entries']['removed_families'] }} removed</span>
                                        @endif
                                    @else
                                        <span class="text-gray-500">None</span>
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
