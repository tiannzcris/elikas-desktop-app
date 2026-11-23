@extends('layouts.app')

@section('title', 'Families to evacuate')
@section('nav-families-to-evacuate', 'active')

@section('content')
    @php
        $summary = $list['summary'];
        $n = fn ($v) => number_format((int) $v);
    @endphp
    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Families to evacuate</h1>
            <p class="page-subtitle flex flex-wrap items-center gap-2">
                Barangay {{ $currentUser->barangay_name }}
                @if ($event)<span aria-hidden="true">·</span> <x-event-name :event="$event" />@endif
            </p>
        </div>
        <div class="page-actions">
            <x-row-menu label="More actions">
                <form method="POST" action="{{ route('families-to-evacuate.refresh') }}">
                    @csrf
                    <button type="submit" class="menu-item" role="menuitem" data-online-only><i class="ti ti-refresh" aria-hidden="true"></i> Refresh the list</button>
                </form>
            </x-row-menu>
        </div>
    </div>

    <p class="text-sm text-gray-700 mb-4">The families each purok expects to move. A listed family is not an evacuee and is in no count until it is checked in.</p>

    @if (! $event || $event->isClosed())
        <x-lock-notice :message="\App\Support\EventLock::reason($event, 'Families to evacuate')" class="mb-6" />
    @elseif ($event->isMonitoring())
        <x-lock-notice :message="\App\Support\EventLock::monitoringMessage($event->name, \App\Support\EventLock::CHECK_IN)" class="mb-6" compact data-check-in-lock>
            Listing families is open now, so they can be checked in with one click once the evacuation starts.
        </x-lock-notice>
    @endif

    @if ($event)
        <div class="stat-strip grid-cols-1 sm:grid-cols-3 mb-6">
            <div class="stat"><p class="stat-label">Families listed</p><p class="stat-value">{{ $n($summary['families']) }}</p></div>
            <div class="stat"><p class="stat-label">Persons listed</p><p class="stat-value">{{ $n($summary['persons']) }}</p></div>
            <div class="stat"><p class="stat-label">Checked in</p><p class="stat-value" data-checked-in-summary>{{ $n($summary['checked_in']) }} <span class="text-base font-normal text-gray-600">of {{ $n($summary['families']) }}</span></p></div>
        </div>
        <p class="text-sm text-gray-700 -mt-3 mb-6" data-as-of>List as of {{ $list['fetched_at'] ? \App\Support\Format::when($list['fetched_at']) : 'never fetched' }}. Check-ins made here show at once; the rest of the list updates when the server answers.</p>
    @endif

    @if ($mayEdit)
        {{-- Fast entry: Enter adds the family; the purok stays for the next one. --}}
        <section class="card mb-6" aria-labelledby="add-title" data-list-entry>
            <div class="card-body">
                <div class="card-header">
                    <h2 id="add-title" class="card-title">Add a family</h2>
                    <span class="text-sm text-gray-600">Enter adds it. The purok stays for the next family. Needs a connection.</span>
                </div>
                <form method="POST" action="{{ route('families-to-evacuate.store') }}" class="grid gap-3 grid-cols-2 lg:grid-cols-12 items-end">
                    @csrf
                    <div class="col-span-2 lg:col-span-2">
                        <label for="fte-purok" class="label">Purok</label>
                        <select id="fte-purok" name="purok_id" class="input" data-list-purok>
                            <option value="">No purok</option>
                            @foreach ($puroks as $p)
                                <option value="{{ $p->id }}" @selected((int) old('purok_id', $selectedPurok) === $p->id)>{{ $p->name }}{{ $p->isHighRisk() ? ' (high risk)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-2">
                        <label for="fte-first" class="label">Head: first name</label>
                        <input id="fte-first" name="head_first_name" value="{{ old('head_first_name') }}" class="input" required autocomplete="off" @if (session('listAdded') || $selectedPurok) autofocus @endif>
                    </div>
                    <div class="lg:col-span-2">
                        <label for="fte-last" class="label">Last name</label>
                        <input id="fte-last" name="head_last_name" value="{{ old('head_last_name') }}" class="input" required autocomplete="off">
                    </div>
                    <div class="lg:col-span-1">
                        <label for="fte-sex" class="label">Sex</label>
                        <select id="fte-sex" name="head_sex" class="input">
                            <option value="">–</option>
                            <option value="female" @selected(old('head_sex') === 'female')>F</option>
                            <option value="male" @selected(old('head_sex') === 'male')>M</option>
                        </select>
                    </div>
                    <div class="lg:col-span-1">
                        <label for="fte-members" class="label">Members</label>
                        <input id="fte-members" name="members_count" type="number" min="1" max="30" value="{{ old('members_count', 1) }}" class="input" required>
                    </div>
                    <div class="lg:col-span-2">
                        <label for="fte-contact" class="label">Contact (optional)</label>
                        <input id="fte-contact" name="contact_number" value="{{ old('contact_number') }}" class="input" autocomplete="off" inputmode="tel">
                    </div>
                    <div class="col-span-2 lg:col-span-2">
                        <button type="submit" class="btn btn-primary w-full" data-online-only><i class="ti ti-plus" aria-hidden="true"></i> Add to list</button>
                    </div>
                </form>

                {{-- From past events: one per household; head and members come from the old record. --}}
                <form method="GET" action="{{ route('families-to-evacuate.index') }}" class="mt-5 flex flex-wrap items-end gap-3" data-past-search>
                    <input type="hidden" name="purok" value="{{ $selectedPurok }}">
                    <div class="flex-1 min-w-[16rem]">
                        <label for="fte-search" class="label">Or find a family from past events</label>
                        <input id="fte-search" name="search" type="search" value="{{ $search }}" class="input" placeholder="Head of the family's name" autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-secondary" data-online-only><i class="ti ti-search" aria-hidden="true"></i> Search past events</button>
                </form>
                @if ($pastError)
                    <p class="text-sm text-red-700 mt-2">{{ $pastError }}</p>
                @elseif (is_array($past))
                    @if (empty($past))
                        <p class="text-sm text-gray-600 mt-3">No family from past events matches "{{ $search }}".</p>
                    @else
                        <ul class="mt-3 divide-y divide-gray-100 border border-gray-100 rounded-lg" data-past-results>
                            @foreach ($past as $pf)
                                @php $pfName = $pf['head'] ?? $pf['name'] ?? ('Family #'.$pf['id']); @endphp
                                <li class="px-4 py-2 flex flex-wrap items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-900">{{ $pfName }}</p>
                                        <p class="text-sm text-gray-600">
                                            {{ \App\Support\Plural::of($pf['members_count'] ?? $pf['member_count'] ?? 0, 'member', 'members') }}
                                            @if (! empty($pf['event']['name'] ?? $pf['last_event']['name'] ?? null)) · {{ $pf['event']['name'] ?? $pf['last_event']['name'] }}@endif
                                            @if (! empty($pf['purok']['name'])) · {{ $pf['purok']['name'] }}@endif
                                        </p>
                                    </div>
                                    @if (! empty($pf['already_listed']))
                                        <span class="badge badge-neutral">Already listed</span>
                                    @else
                                        <form method="POST" action="{{ route('families-to-evacuate.store') }}">
                                            @csrf
                                            <input type="hidden" name="source_family_id" value="{{ $pf['id'] }}">
                                            <input type="hidden" name="label" value="{{ $pfName }}">
                                            <input type="hidden" name="purok_id" value="{{ $selectedPurok }}">
                                            <button type="submit" class="btn btn-secondary btn-sm" data-online-only>Add to list</button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>
        </section>
    @endif

    @if ($event && ! $list['has_copy'])
        <div class="card"><x-empty icon="ti-cloud-download" title="The list is not on this device yet." text="Refresh while online; the copy stays readable offline, and checking in works offline."><form method="POST" action="{{ route('families-to-evacuate.refresh') }}">@csrf<button type="submit" class="btn btn-secondary" data-online-only><i class="ti ti-refresh" aria-hidden="true"></i> Refresh now</button></form></x-empty></div>
    @elseif ($event && $list['groups']->isEmpty())
        <div class="card"><x-empty icon="ti-list-check" title="No families listed yet." text="Add the families each purok expects to move, starting with the high-risk puroks." compact /></div>
    @endif

    @foreach ($list['groups'] as $group)
        <section class="card mb-4" aria-label="{{ $group['name'] }}" data-purok-group="{{ $group['purok']?->remote_id ?? 0 }}">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-b border-gray-100">
                <div class="flex flex-wrap items-center gap-2 min-w-0">
                    <h2 class="card-title">{{ $group['name'] }}</h2>
                    @if ($group['high_risk'])
                        <span class="badge badge-danger" data-high-risk><i class="ti ti-alert-triangle" aria-hidden="true"></i> High risk{{ $group['risk'] ? ': '.$group['risk'] : '' }}</span>
                    @endif
                    <span class="text-sm text-gray-600">{{ \App\Support\Plural::of($group['families']->count(), 'family', 'families') }} · {{ \App\Support\Plural::of($group['persons'], 'person', 'persons') }} · {{ $n($group['checked_in']) }} checked in</span>
                </div>
                @if ($group['purok'])
                    @include('families-to-evacuate._purok_marks', ['purok' => $group['purok']])
                @endif
            </div>
            <ul class="divide-y divide-gray-100">
                @foreach ($group['families'] as $f)
                    <li class="px-5 py-2.5 flex flex-wrap items-center justify-between gap-3" data-listed-family="{{ $f['id'] }}">
                        <div class="min-w-0">
                            <p class="font-semibold text-sm text-gray-900">{{ $f['head'] ?? 'Family #'.$f['id'] }}
                                @if (! empty($f['from_past_event']))<span class="badge badge-neutral ml-1">From a past event</span>@endif
                            </p>
                            <p class="text-xs text-gray-600">
                                {{ \App\Support\Plural::of($f['members_count'], 'member', 'members') }}{{ ! empty($f['contact_number']) ? ' · '.$f['contact_number'] : '' }}{{ ! empty($f['note']) ? ' · '.$f['note'] : '' }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($f['waiting_check_in'])
                                <span class="badge badge-warning" data-check-in-waiting>Checked in at {{ $f['waiting_check_in']->destination() }}, waiting to sync</span>
                            @elseif ($f['is_checked_in'])
                                <span class="badge badge-success">Checked in{{ ! empty($f['checked_in_at']) ? ' '.\App\Support\Format::when($f['checked_in_at']) : '' }}</span>
                            @elseif ($mayCheckIn)
                                <form method="POST" action="{{ route('families-to-evacuate.check-in', $f['id']) }}" class="flex flex-wrap items-center gap-2" data-check-in-form>
                                    @csrf
                                    <select name="destination" class="input input-sm w-auto" aria-label="Where {{ $f['head'] }} goes" data-check-in-destination required>
                                        @foreach ($centers as $c)
                                            <option value="center-{{ $c->id }}">{{ $c->name }}{{ $c->isUnverified() ? ' (Pending CSWD approval)' : '' }}</option>
                                        @endforeach
                                        <option value="private">Private house (relatives or friends)</option>
                                    </select>
                                    <span class="flex flex-wrap items-center gap-2" data-private-fields hidden>
                                        <input name="host_name" class="input input-sm w-40" placeholder="Staying with (name)" aria-label="Staying with">
                                        <select name="host_purok_id" class="input input-sm w-auto" aria-label="Host's purok">
                                            <option value="">Host's purok…</option>
                                            @foreach ($puroks as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                                        </select>
                                        <input name="host_address" class="input input-sm w-40" placeholder="or address" aria-label="Host's address">
                                    </span>
                                    <button type="submit" class="btn btn-primary btn-sm">Check in</button>
                                </form>
                            @else
                                <span class="badge badge-neutral">Expected</span>
                            @endif
                            @if ($mayEdit && ! $f['is_checked_in'])
                                <x-row-menu label="Actions for {{ $f['head'] }}">
                                    <form method="POST" action="{{ route('families-to-evacuate.destroy', $f['id']) }}" data-confirm-title="Remove {{ $f['head'] }} from the list?" data-confirm="Only the list changes; no evacuee record is touched." data-confirm-label="Remove" data-confirm-tone="danger">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="menu-item menu-item-danger" role="menuitem" data-online-only><i class="ti ti-trash" aria-hidden="true"></i> Remove from list</button>
                                    </form>
                                </x-row-menu>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach

    @if ($puroks->isNotEmpty() && $list['groups']->whereNotNull('purok')->count() < $puroks->count())
        {{-- Puroks with no family listed yet, so they can be marked high risk too. --}}
        <section class="card" aria-labelledby="other-puroks-title">
            <div class="card-body">
                <div class="card-header"><h2 id="other-puroks-title" class="card-title">Puroks with no family listed</h2></div>
                <ul class="divide-y divide-gray-100">
                    @foreach ($puroks->reject(fn ($p) => $list['groups']->contains(fn ($g) => $g['purok']?->id === $p->id)) as $p)
                        <li class="py-2 flex flex-wrap items-center justify-between gap-3">
                            <span class="font-medium text-gray-900">{{ $p->name }}
                                @if ($p->isHighRisk())<span class="badge badge-danger ml-1">High risk: {{ $p->riskLabel() }}</span>@endif
                            </span>
                            @include('families-to-evacuate._purok_marks', ['purok' => $p])
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // A private house asks who the family stays with.
        document.querySelectorAll('[data-check-in-form]').forEach((form) => {
            const select = form.querySelector('[data-check-in-destination]');
            const fields = form.querySelector('[data-private-fields]');
            const sync = () => { fields.hidden = select.value !== 'private'; fields.querySelector('[name=host_name]').required = select.value === 'private'; };
            select.addEventListener('change', sync);
            sync();
        });
    });
</script>
@endsection
