{{-- Families of this barangay staying with relatives or friends, outside the centers. --}}
<a href="{{ route('private-houses.index') }}" class="card card-link card-body flex flex-col gap-3" data-private-houses-card>
    <div class="flex items-start justify-between gap-3">
        <p class="card-title min-w-0">Private houses</p>
        <span class="badge badge-neutral shrink-0">Outside centers</span>
    </div>
    <div>
        <p class="font-label font-bold text-[2rem] leading-none tabular-nums text-ink">{{ \App\Support\Plural::of($privateHouses['family_count'], 'family', 'families') }}</p>
        <p class="mt-1 text-sm text-gray-600">{{ \App\Support\Plural::of($privateHouses['person_count'], 'person', 'people') }} with relatives or friends</p>
    </div>
    @if ($privateHouses['waiting'] > 0)
        <p class="text-sm font-medium text-amber-800">{{ \App\Support\Plural::of($privateHouses['waiting'], 'family', 'families') }} waiting to sync</p>
    @else
        <p class="text-sm"><span class="link">Add family or see the list</span></p>
    @endif
</a>
