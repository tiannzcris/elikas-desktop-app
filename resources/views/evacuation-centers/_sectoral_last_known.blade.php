{{-- The "Last known" (server-synced) half of the sectoral/4Ps dual view --
     see EvacuationCenterSectoralSnapshot's own docblock. Read-only,
     deliberately never combined with the Pending card below it: same
     reasoning as _breakdown_table.blade.php's own note on the age/sex
     tables above. --}}
@if (! $snapshot)
    <p class="text-sm text-gray-400">Not yet refreshed from the central server for this event.</p>
@else
    <div class="flex items-center gap-1.5 text-sm text-gray-700 mb-3">
        <span class="text-gray-400">4Ps beneficiary families:</span>
        <span class="font-semibold">{{ $snapshot->beneficiaries_4ps }}</span>
    </div>
    <table class="w-full text-xs">
        <thead>
            <tr class="text-gray-400 text-left">
                <th class="pb-2 font-medium">Sectoral group</th>
                <th class="pb-2 font-medium text-right">Male</th>
                <th class="pb-2 font-medium text-right">Female</th>
            </tr>
        </thead>
        <tbody>
            @php $rows = collect($snapshot->sectoral_groups)->keyBy('sectoral_group'); @endphp
            @foreach ($sectoralGroups as $groupKey => $groupLabel)
                <tr class="border-t border-gray-100">
                    <td class="py-1.5 text-gray-600">{{ $groupLabel }}</td>
                    <td class="py-1.5 text-right text-gray-800">{{ $rows[$groupKey]['male_count'] ?? 0 }}</td>
                    <td class="py-1.5 text-right text-gray-800">{{ $rows[$groupKey]['female_count'] ?? 0 }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @if ($snapshot->updated_by_name || $snapshot->server_updated_at)
        <p class="text-xs text-gray-400 mt-3">
            Last reported{{ $snapshot->updated_by_name ? ' by '.$snapshot->updated_by_name : '' }}{{ $snapshot->server_updated_at ? ' on '.$snapshot->server_updated_at->format('M j, Y g:i A') : '' }}.
        </p>
    @endif
@endif
