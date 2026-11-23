@extends('layouts.app')

@section('title', 'Returning families · '.$center->name)
@section('nav-ec-board', 'active')

@section('content')
    <a href="{{ route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]) }}" class="back-link">
        <i class="ti ti-arrow-left" style="font-size: 14px;" aria-hidden="true"></i> {{ $center->name }}
    </a>

    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Returning families</h1>
            <p class="page-subtitle flex items-center gap-1">Saves to: <x-event-name :event="$event" /></p>
        </div>
    </div>

    @if (! $snapshot || $pastEvents->isEmpty())
        <div class="card">
            @if ($snapshot)
                <x-empty icon="ti-history" title="No families from past events to bring back." text="Use Add evacuee for families coming for the first time." />
            @else
                <x-empty icon="ti-cloud-download" title="Returning families are not on this device yet." text="Refresh reference data while online."><form method="POST" action="{{ route('reference-data.refresh') }}">@csrf<button type="submit" class="btn btn-secondary" data-online-only><i class="ti ti-refresh" aria-hidden="true"></i> Refresh reference data</button></form></x-empty>
            @endif
        </div>
    @else
        <div class="flex flex-wrap items-end gap-4 mb-6">
            <form method="GET" action="{{ route('returning-families.index', $center) }}">
                <input type="hidden" name="event" value="{{ $event->id }}">
                <label for="rf-source" class="label">Past event</label>
                <select id="rf-source" name="source" class="input w-auto min-w-[16rem]" onchange="this.form.submit()">
                    @foreach ($pastEvents as $past)
                        <option value="{{ $past['id'] }}" @selected($sourceId === $past['id'])>{{ $past['name'] }} ({{ \App\Support\Plural::of($past['family_count'] ?? 0, 'family', 'families') }})</option>
                    @endforeach
                </select>
            </form>
            <div class="flex-1 min-w-[14rem]">
                <label for="rf-search" class="label">Search</label>
                <input id="rf-search" type="search" class="input" placeholder="Family or member name" autofocus data-rf-search>
            </div>
            <p class="text-sm text-gray-600 ml-auto">As of
                <time datetime="{{ $snapshot->fetched_at->toIso8601String() }}">{{ \App\Support\Format::when($snapshot->fetched_at) }}</time>
            </p>
        </div>

        <div class="flex flex-col gap-3" data-rf-list>
            @foreach ($families as $family)
                @php
                    $search = strtolower($family['name'].' '.collect($family['members'])->pluck('full_name')->implode(' '));
                    $blocked = $family['queued'] || ! empty($family['re_enrolled_family_id']);
                @endphp
                <details class="card" data-rf-family data-search="{{ $search }}">
                    <summary class="card-body flex flex-wrap items-center justify-between gap-2 cursor-pointer">
                        <span class="min-w-0">
                            <span class="font-semibold text-gray-900">{{ $family['name'] }}</span>
                            <span class="text-sm text-gray-600">
                                · {{ \App\Support\Plural::of(count($family['members']), 'person', 'people') }}
                                @if (! empty($family['purok'])) · {{ $family['purok']['name'] }} @endif
                                @if (! empty($family['last_center'])) · last at {{ $family['last_center']['name'] }} @endif
                            </span>
                        </span>
                        @if ($family['queued'])
                            <span class="badge badge-warning">Waiting to send</span>
                        @elseif (! empty($family['re_enrolled_family_id']))
                            <span class="badge badge-neutral">Already in this event</span>
                        @endif
                    </summary>
                    @unless ($blocked)
                        <form method="POST" action="{{ route('returning-families.store', $center) }}" class="px-4 sm:px-6 pb-6 flex flex-col gap-3">
                            @csrf
                            <input type="hidden" name="evacuation_event_id" value="{{ $event->id }}">
                            <input type="hidden" name="source_event_id" value="{{ $sourceId }}">
                            <input type="hidden" name="source_family_id" value="{{ $family['id'] }}">
                            <p class="label mb-0">Who arrived?</p>
                            @foreach ($family['members'] as $m)
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-gray-100 pt-3">
                                    <label class="flex items-center gap-2 min-w-[14rem]">
                                        <input type="checkbox" name="members[{{ $m['id'] }}][ticked]" value="1" @disabled($m['checked_in'])>
                                        <span class="{{ $m['checked_in'] ? 'text-gray-500' : 'text-gray-900' }}">{{ $m['full_name'] }}</span>
                                        <span class="text-sm text-gray-600">{{ ucfirst($m['sex']) }}{{ ($m['age'] ?? null) !== null ? ', '.$m['age'] : '' }}{{ ! empty($m['is_head']) ? ', head' : '' }}</span>
                                    </label>
                                    @if ($m['checked_in'])
                                        <span class="badge badge-neutral">Already checked in</span>
                                    @else
                                        @if (! empty($m['needs_age_bracket']))
                                            <select name="members[{{ $m['id'] }}][age_bracket]" class="input input-sm w-auto" aria-label="Age group of {{ $m['full_name'] }}">
                                                <option value="">Age group…</option>
                                                @foreach ($ageBrackets as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                        @if ($m['sex'] === 'female' && ! empty($m['was_pregnant']))
                                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="members[{{ $m['id'] }}][is_pregnant]" value="1"> Pregnant now</label>
                                        @endif
                                        @if ($m['sex'] === 'female' && ! empty($m['was_lactating']))
                                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="members[{{ $m['id'] }}][is_lactating]" value="1"> Lactating now</label>
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                            @if (($family['unnamed_member_count'] ?? 0) > 0)
                                <p class="help">{{ \App\Support\Plural::of($family['unnamed_member_count'], 'person', 'people') }} without a name cannot be brought back; add them with Add evacuee.</p>
                            @endif
                            <div class="flex justify-end">
                                <button type="submit" class="btn btn-primary">Add to this center</button>
                            </div>
                        </form>
                    @endunless
                </details>
            @endforeach
            <p class="text-sm text-gray-600" data-rf-none hidden>No family matches.</p>
        </div>
    @endif
@endsection

@section('scripts')
    <script>
        // Name search over the list on this page (offline).
        document.querySelector('[data-rf-search]')?.addEventListener('input', (e) => {
            const words = e.target.value.toLowerCase().split(/\s+/).filter(Boolean);
            let shown = 0;
            document.querySelectorAll('[data-rf-family]').forEach((card) => {
                const match = words.every((w) => card.dataset.search.includes(w));
                card.hidden = !match;
                if (match) shown++;
            });
            document.querySelector('[data-rf-none]').hidden = shown > 0;
        });
    </script>
@endsection
