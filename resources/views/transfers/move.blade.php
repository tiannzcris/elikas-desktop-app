@extends('layouts.app')

@section('title', 'Move families · '.$center->name)
@section('nav-ec-board', 'active')

@section('content')
    @php
        $names = collect($families)->pluck('label')->implode(', ');
        $summary = \App\Support\Plural::of(count($families), 'family', 'families').' ('.\App\Support\Plural::of($persons, 'person', 'people').')';
    @endphp
    <a href="{{ route('evacuation-centers.ec-board', $center) }}" class="back-link">
        <i class="ti ti-arrow-left" style="font-size: 14px;" aria-hidden="true"></i> {{ $center->name }}
    </a>

    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Move {{ $summary }}</h1>
            <p class="page-subtitle">From {{ $center->name }} · Needs a connection</p>
        </div>
    </div>

    @if (! $families)
        <div class="card"><x-empty icon="ti-users" title="No family chosen." text="Tick families under Families here on the board. Families still waiting to sync can't be moved until they are sent.">
            <a href="{{ route('evacuation-centers.ec-board', $center) }}" class="btn btn-secondary">Back to the board</a>
        </x-empty></div>
    @else
        <ul class="flex flex-wrap gap-2 mb-6 text-sm text-gray-800">
            @foreach ($families as $f)
                <li class="rounded-md bg-white px-2 py-1 ring-1 ring-gray-200">{{ $f['label'] }} · {{ $f['here'] }} here</li>
            @endforeach
        </ul>

        <div class="grid gap-6 xl:grid-cols-2">
            {{-- Another of our centers: a direct move, no approval. --}}
            <section class="card card-body flex flex-col gap-4" aria-labelledby="own-title">
                <h2 id="own-title" class="section-title">Move to another of our centers</h2>
                @if ($ownCenters->isEmpty())
                    <p class="text-gray-700">Your barangay has no other open center.</p>
                @else
                    <form method="POST" action="{{ route('transfers.move-direct', $center) }}" class="flex flex-col gap-4"
                        data-confirm-title="Move {{ $summary }}?" data-confirm="{{ $names }} move from {{ $center->name }} now." data-confirm-label="Move families">
                        @csrf
                        @foreach ($families as $f)<input type="hidden" name="families[]" value="remote-{{ $f['remote_id'] }}">@endforeach
                        <div>
                            <label for="own-center" class="label">Center</label>
                            <select id="own-center" name="to_center_id" class="input" required>
                                <option value="">Choose…</option>
                                @foreach ($ownCenters as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }} · {{ number_format($c->occupancyHere()) }} / {{ $c->capacity_persons ? number_format($c->capacity_persons) : '–' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="own-reason" class="label">Reason <span class="font-normal text-gray-600">(optional)</span></label>
                            <input id="own-reason" name="reason" class="input" maxlength="500" placeholder="e.g. Our school is full">
                        </div>
                        <div class="flex justify-end"><button type="submit" class="btn btn-secondary" data-online-only>Move families</button></div>
                    </form>
                @endif
            </section>

            {{-- Another barangay's center: a request it accepts or declines. --}}
            @if ($currentUser->can('transfers.request'))
                <section class="card card-body flex flex-col gap-4" aria-labelledby="ask-title">
                    <h2 id="ask-title" class="section-title">Ask another barangay's center</h2>
                    @if ($suggestionError)
                        <p class="callout callout-warning" role="status">{{ $suggestionError }}</p>
                    @elseif (empty($suggestions))
                        <p class="text-gray-700">No other barangay's center has space.</p>
                    @else
                        <form method="POST" action="{{ route('transfers.request', $center) }}" class="flex flex-col gap-4"
                            data-confirm-title="Ask to move {{ $summary }}?" data-confirm="{{ $names }} stay at {{ $center->name }} until the other barangay accepts." data-confirm-label="Send request">
                            @csrf
                            @foreach ($families as $f)<input type="hidden" name="families[]" value="remote-{{ $f['remote_id'] }}">@endforeach
                            <fieldset class="flex flex-col gap-2">
                                <legend class="label">Centers with room, nearest first</legend>
                                @foreach ($suggestions as $s)
                                    <label class="flex items-start gap-3 rounded-lg border border-gray-200 px-3 py-2.5 hover:bg-gray-50" data-suggestion>
                                        <input type="radio" name="to_center_remote_id" value="{{ $s['evacuation_center']['id'] }}" class="mt-1" required @checked($loop->first)>
                                        <span class="min-w-0 flex-1">
                                            <span class="font-medium text-gray-900">{{ $s['evacuation_center']['name'] }}</span>
                                            <span class="text-sm text-gray-600">· {{ $s['barangay']['name'] ?? '' }}
                                                @if (($s['distance_km'] ?? null) !== null) · {{ ($s['distance_basis'] ?? '') === 'barangay_centroid' ? 'about ' : '' }}{{ $s['distance_km'] }} km @endif
                                            </span>
                                            <span class="block text-sm">
                                                @if (! empty($s['capacity_unknown']))
                                                    <span class="badge badge-neutral">Capacity unknown</span>
                                                @elseif (! empty($s['fits']))
                                                    <span class="text-green-800">Room for {{ number_format($s['remaining_persons']) }}</span>
                                                @else
                                                    <span class="text-amber-800">Room for {{ number_format($s['remaining_persons'] ?? 0) }}</span>
                                                @endif
                                            </span>
                                        </span>
                                    </label>
                                @endforeach
                            </fieldset>
                            <div>
                                <label for="ask-note" class="label">Note to that barangay <span class="font-normal text-gray-600">(optional)</span></label>
                                <input id="ask-note" name="note" class="input" maxlength="500" value="{{ old('note') }}" placeholder="e.g. Our hall is full">
                            </div>
                            <div class="flex justify-end"><button type="submit" class="btn btn-primary" data-online-only>Send request</button></div>
                        </form>
                    @endif
                </section>
            @endif
        </div>
    @endif
@endsection
