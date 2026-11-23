@extends('print.layout')

@section('body')
    @php
        $snap = $sectoralSnapshot;
        $known = collect($snap?->sectoral_groups ?? [])->keyBy('sectoral_group');
        $v = fn ($x) => $x === null ? '—' : number_format((int) $x);
    @endphp
    <div class="strip">
        <div>No. of families (cum / now)<b>{{ $v($snap?->families_cumulative) }} / {{ $v($snap?->families_now) }}</b></div>
        <div>No. of persons (cum / now)<b>{{ $v($snap?->persons_cumulative) }} / {{ $v($snap?->persons_now) }}</b></div>
        <div>4Ps beneficiary families<b>{{ $v($snap?->beneficiaries_4ps) }}</b></div>
        <div>Added on this computer, not yet synced<b>{{ number_format($pendingCount) }}</b></div>
    </div>
    <p class="muted">Server figures as of {{ $snap?->fetched_at ? \App\Support\Format::when($snap->fetched_at) : 'never fetched' }}. The last column is this computer's own entries, not yet on the server.</p>

    <h2>Age and sex</h2>
    <table>
        <thead><tr><th>Age group</th><th class="num">Male</th><th class="num">Female</th><th class="num">Total</th><th class="num">Not yet synced</th></tr></thead>
        <tbody>
            @foreach ($breakdownAgeBrackets as $bracket => $label)
                <tr><td>{{ $label }}</td><td class="num">{{ number_format($lastKnownBreakdown[$bracket]['male']) }}</td><td class="num">{{ number_format($lastKnownBreakdown[$bracket]['female']) }}</td>
                    <td class="num">{{ number_format($lastKnownBreakdown[$bracket]['total']) }}</td><td class="num">{{ $pendingBreakdown[$bracket]['total'] ?: '' }}</td></tr>
            @endforeach
            <tr><th>Total</th><th class="num">{{ number_format($lastKnownBreakdown['total']['male']) }}</th><th class="num">{{ number_format($lastKnownBreakdown['total']['female']) }}</th>
                <th class="num">{{ number_format($lastKnownBreakdown['total']['total']) }}</th><th class="num">{{ $pendingBreakdown['total']['total'] ?: '' }}</th></tr>
        </tbody>
    </table>

    <h2>Sectoral groups</h2>
    <table>
        <thead><tr><th>Group</th><th class="num">Male</th><th class="num">Female</th><th class="num">Total</th><th class="num">Not yet synced</th></tr></thead>
        <tbody>
            @foreach ($sectoralGroups as $group => $label)
                @php $m = (int) ($known[$group]['male_count'] ?? 0); $f = (int) ($known[$group]['female_count'] ?? 0); $p = $pendingSectoral[$group]['male'] + $pendingSectoral[$group]['female']; @endphp
                <tr><td>{{ $label }}</td><td class="num">{{ number_format($m) }}</td><td class="num">{{ number_format($f) }}</td><td class="num">{{ number_format($m + $f) }}</td><td class="num">{{ $p ?: '' }}</td></tr>
            @endforeach
        </tbody>
    </table>
@endsection
