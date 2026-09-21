@php
    // Precedence: this device's own PENDING not-yet-synced edit (its own
    // more-recent, unsynced intent) beats the "Last known" synced
    // snapshot, which beats empty defaults -- same three-way precedence
    // as the mobile app's own now-proven version of this exact form.
    $sectoralRows = $quickCount ? $quickCount->sectoralGroups->keyBy('sectoral_group') : collect();
    $snapshotRows = $snapshot ? collect($snapshot->sectoral_groups)->keyBy('sectoral_group') : collect();
    $beneficiaries4ps = $quickCount->beneficiaries_4ps ?? $snapshot->beneficiaries_4ps ?? 0;
@endphp
{{-- Modal chrome for editing the sectoral/4Ps figures -- mirrors
     _entry_form.blade.php's own wrapper exactly (same modal-pop/
     modal-close-btn/form-errors conventions), just a different field
     partial inline below instead of a shared one, since this form's
     shape (one row per sectoral group, no household picker) doesn't
     overlap with _entry_fields.blade.php at all. --}}
<div class="modal-pop w-full max-w-xl bg-white rounded-3xl shadow-2xl" data-sectoral-modal>
    <div class="flex items-start justify-between px-6 pt-6">
        <div>
            <h1 class="text-xl font-bold text-brand mb-1">Edit sectoral & 4Ps</h1>
            <p class="text-sm text-gray-500">Still saved only on this device -- sync when you're back online.</p>
        </div>
        <a href="{{ route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]) }}" class="modal-close-btn w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 shrink-0" aria-label="Close">
            <i class="ti ti-x" style="font-size: 18px;" aria-hidden="true"></i>
        </a>
    </div>

    <div class="form-errors mx-6 mt-4 bg-red-50 text-red-700 text-sm rounded-xl p-3" @if (! $errors->any()) style="display: none;" @endif>
        {{ $errors->first() }}
    </div>

    <form method="POST" action="{{ route('evacuation-centers.sectoral.update', $center) }}" class="flex flex-col gap-4 p-6">
        @csrf
        <input type="hidden" name="evacuation_event_id" value="{{ $event->id }}">

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1" for="beneficiaries-4ps">4Ps beneficiary families</label>
            <input type="number" min="0" name="beneficiaries_4ps" id="beneficiaries-4ps" value="{{ $beneficiaries4ps }}" class="w-28 border border-gray-200 rounded-xl px-3 py-2 text-sm">
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-gray-400 text-left">
                        <th class="pb-2 font-medium">Sectoral group</th>
                        <th class="pb-2 font-medium w-24">Male</th>
                        <th class="pb-2 font-medium w-24">Female</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sectoralGroups as $groupKey => $groupLabel)
                        @php
                            $maleCount = $sectoralRows[$groupKey]->male_count ?? $snapshotRows[$groupKey]['male_count'] ?? 0;
                            $femaleCount = $sectoralRows[$groupKey]->female_count ?? $snapshotRows[$groupKey]['female_count'] ?? 0;
                        @endphp
                        <tr class="border-t border-gray-100">
                            <td class="py-1.5 text-gray-600">
                                {{ $groupLabel }}
                                <input type="hidden" name="sectoral_groups[{{ $loop->index }}][sectoral_group]" value="{{ $groupKey }}">
                            </td>
                            <td class="py-1.5">
                                <input type="number" min="0" name="sectoral_groups[{{ $loop->index }}][male_count]" value="{{ $maleCount }}" class="w-20 border border-gray-200 rounded-xl px-2 py-1.5 text-xs">
                            </td>
                            <td class="py-1.5">
                                <input type="number" min="0" name="sectoral_groups[{{ $loop->index }}][female_count]" value="{{ $femaleCount }}" class="w-20 border border-gray-200 rounded-xl px-2 py-1.5 text-xs">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <button type="submit" class="btn-modern btn-primary-modern bg-brand hover:bg-brand-dark text-white text-sm px-4 py-2.5 w-fit">
            Save sectoral figures (offline)
        </button>
    </form>
</div>
