{{-- The board sheet's figures, in the official EC Information Board's order:
     header figures, then Age & Sex, then Sectoral -- sharing one column
     grid so Male/Female/Total line up down the whole board. Rendered by the
     page and returned again by the refresh endpoint (see
     EvacuationCenterController::boardData()).

     Two sources, side by side on every row and never summed: the header
     counts and Male/Female/Total are the central server's figures as last
     fetched ("As of"); "On this device" is what was added here and hasn't
     synced yet. --}}
@php
    $lastKnownSectoral = collect($sectoralSnapshot?->sectoral_groups ?? [])->keyBy('sectoral_group');
    $cumNow = fn ($cumulative, $now) => ($cumulative ?? '—').'/'.($now ?? '—');
    $fetchedAt = $sectoralSnapshot?->fetched_at;
@endphp

{{-- "As of": when this board's server figures last arrived -- stays put
     offline, so staff can see how old the figures are. Formatted on the
     device (app.js localizeTimes()) in its own local time. --}}
<p class="ecb-asof">
    As of:
    @if ($fetchedAt)
        <time datetime="{{ $fetchedAt->toIso8601String() }}" data-local-time>{{ $fetchedAt->format('M j, Y g:i A') }} UTC</time>
    @else
        <span class="text-gray-600">not yet fetched from the central server -- connect to the internet to load this board.</span>
    @endif
</p>

<div class="ecb-strip">
    <div>
        <p class="ecb-strip-label">No. of Families (Cum/Now)</p>
        <p class="ecb-strip-value">{{ $cumNow($sectoralSnapshot?->families_cumulative, $sectoralSnapshot?->families_now) }}</p>
    </div>
    <div>
        <p class="ecb-strip-label">No. of Persons (Cum/Now)</p>
        <p class="ecb-strip-value">{{ $cumNow($sectoralSnapshot?->persons_cumulative, $sectoralSnapshot?->persons_now) }}</p>
    </div>
    <div>
        <p class="ecb-strip-label">4Ps beneficiary families</p>
        <p class="ecb-strip-value">{{ $sectoralSnapshot->beneficiaries_4ps ?? '—' }}</p>
    </div>
    <div class="ecb-strip-pending">
        <p class="ecb-strip-label">Added on this device, not yet synced</p>
        <p class="ecb-strip-value">{{ $pendingCount }}</p>
    </div>
</div>

<table class="ecb-table">
    <colgroup><col><col class="ecb-num"><col class="ecb-num"><col class="ecb-num"><col class="ecb-pending-col"></colgroup>
    <thead>
        <tr>
            <th>Age group</th>
            <th>Male</th>
            <th>Female</th>
            <th>Total</th>
            <th class="ecb-pending" title="Added on this device and not yet synced -- not in the totals until it syncs.">On this device</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($breakdownAgeBrackets as $key => $label)
            @php $pending = $pendingBreakdown[$key]; @endphp
            <tr>
                <td>{{ $label }}</td>
                <td>{{ $lastKnownBreakdown[$key]['male'] }}</td>
                <td>{{ $lastKnownBreakdown[$key]['female'] }}</td>
                <td class="font-semibold">{{ $lastKnownBreakdown[$key]['total'] }}</td>
                @include('evacuation-centers._board_pending_cell', ['male' => $pending['male'], 'female' => $pending['female']])
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td>Total</td>
            <td>{{ $lastKnownBreakdown['total']['male'] }}</td>
            <td>{{ $lastKnownBreakdown['total']['female'] }}</td>
            <td>{{ $lastKnownBreakdown['total']['total'] }}</td>
            @include('evacuation-centers._board_pending_cell', ['male' => $pendingBreakdown['total']['male'], 'female' => $pendingBreakdown['total']['female']])
        </tr>
    </tfoot>
</table>

<table class="ecb-table ecb-table-follow">
    <colgroup><col><col class="ecb-num"><col class="ecb-num"><col class="ecb-num"><col class="ecb-pending-col"></colgroup>
    <thead>
        <tr>
            <th>Sectoral group</th>
            <th>Male</th>
            <th>Female</th>
            <th>Total</th>
            <th class="ecb-pending">On this device</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($sectoralGroups as $group => $label)
            @php
                $male = $lastKnownSectoral[$group]['male_count'] ?? 0;
                $female = $lastKnownSectoral[$group]['female_count'] ?? 0;
            @endphp
            <tr>
                <td>{{ $label }}</td>
                @if ($sectoralSnapshot)
                    <td>{{ $male }}</td>
                    <td>{{ $female }}</td>
                    <td class="font-semibold">{{ $male + $female }}</td>
                @else
                    <td class="text-gray-500">—</td>
                    <td class="text-gray-500">—</td>
                    <td class="text-gray-500">—</td>
                @endif
                @include('evacuation-centers._board_pending_cell', ['male' => $pendingSectoral[$group]['male'], 'female' => $pendingSectoral[$group]['female']])
            </tr>
        @endforeach
    </tbody>
</table>

<div class="ecb-notes">
    <p>
        The counts and Male, Female and Total are the central server's figures as of the time above -- they refresh a moment after this page opens while online.
        <span class="ecb-pending-key">On this device</span> is what was added here and hasn't synced -- it joins the totals once it does.
    </p>
    <p>Sectoral groups are counted from each evacuee's ticked details. Child- and single-headed families are counted once per household, by the head's sex.</p>
</div>
