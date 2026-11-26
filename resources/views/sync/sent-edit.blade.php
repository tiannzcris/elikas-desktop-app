@extends('layouts.app')

@section('title', 'Correct a sent record')
@section('nav-sync', 'active')

@section('content')
    @php
        // As the server has them now (GET /families/{id}); null = not yet known.
        $sectoral = $person['sectoral'] ?? [];
        $isHead = $headId !== null && (int) $headId === (int) $entry->remote_id;
        $triState = fn ($value) => $value === null ? '' : ($value ? '1' : '0');
        $flag = fn ($name) => (bool) old($name, $sectoral[$name] ?? false);
        $sex = old('sex', $person['sex'] ?? $entry->sex);
        $headSelf = (bool) old('head_is_self', $isHead);
        $single = old('is_single_headed', $triState($family['is_single_headed'] ?? null));
        $headSex = old('head_sex', $isHead ? '' : ($family['head_sex'] ?? ''));
        $headMinor = old('head_is_minor', $isHead ? '' : $triState($family['is_child_headed'] ?? null));
        $purok = old('purok_id', $family['purok_id'] ?? '');
    @endphp

    <a href="{{ route('sync.index') }}#sent" class="back-link">
        <i class="ti ti-arrow-left" style="font-size: 14px;" aria-hidden="true"></i> Sync · Sent
    </a>

    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Correct a sent record</h1>
            <p class="page-subtitle">{{ $entry->evacuationCenter?->name }} · sent {{ \App\Support\Format::when($entry->synced_at) }}{{ $entry->sent_by ? ' by '.$entry->sent_by : '' }}</p>
        </div>
    </div>

    <div class="card max-w-xl" data-sent-edit>
        <div class="card-body">
            <p class="callout callout-info mb-4 text-sm">This record is already on the server. Changes are saved there directly, so this needs the internet.</p>

            <form method="POST" action="{{ route('sync.sent.update', $entry) }}" class="flex flex-col">
                @csrf
                @method('PATCH')
                <input type="hidden" name="original[head_id]" value="{{ $headId }}">
                <input type="hidden" name="original[is_single_headed]" value="{{ $triState($family['is_single_headed'] ?? null) }}">
                <input type="hidden" name="original[head_sex]" value="{{ $isHead ? '' : ($family['head_sex'] ?? '') }}">
                <input type="hidden" name="original[head_is_minor]" value="{{ $isHead ? '' : $triState($family['is_child_headed'] ?? null) }}">
                <input type="hidden" name="original[purok_id]" value="{{ $family['purok_id'] ?? '' }}">
                <input type="hidden" name="original[age_bracket]" value="{{ $person['age_bracket'] ?? '' }}">
                <input type="hidden" name="original[name]" value="{{ $family['name'] ?? '' }}">

                <fieldset class="entry-section">
                    <legend class="entry-section-title">Who is this person?</legend>
                    <div class="grid grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] gap-3">
                        <div>
                            <label for="sent-age" class="label-sm">Age group</label>
                            @if (! empty($person['date_of_birth']))
                                {{-- A known birthdate decides the age group on the server. --}}
                                <p id="sent-age" class="input bg-gray-50 text-gray-700" data-age-group>{{ $ageBrackets[$person['age_bracket'] ?? ''] ?? ($person['age_bracket'] ?? 'Not recorded') }}</p>
                                <p class="help">Set by the birthdate on file.</p>
                            @else
                                <select id="sent-age" name="age_bracket" class="input" data-age-group>
                                    @if (empty($person['age_bracket']))
                                        <option value="">Not recorded</option>
                                    @endif
                                    @foreach ($ageBrackets as $key => $label)
                                        <option value="{{ $key }}" @selected(old('age_bracket', $person['age_bracket'] ?? '') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                        <div>
                            <label for="sent-sex" class="label-sm">Sex</label>
                            <select id="sent-sex" name="sex" required class="input">
                                <option value="male" @selected($sex === 'male')>Male</option>
                                <option value="female" @selected($sex === 'female')>Female</option>
                            </select>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="entry-section">
                    <legend class="entry-section-title">Family</legend>
                    <div class="mb-3">
                        <label for="sent-family-name" class="label-sm">Family name</label>
                        <input id="sent-family-name" type="text" name="family_name" maxlength="150" class="input" data-family-name
                            value="{{ old('family_name', $family['name'] ?? '') }}" placeholder="{{ $family['head_of_family']['full_name'] ?? 'e.g. Juan Dela Cruz' }}">
                        <p class="help">{{ $family['barangay']['name'] ?? '' }}{{ ! empty($family['purok']['name']) ? ' · '.$family['purok']['name'] : '' }}</p>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-700 mb-3">
                        <input type="checkbox" name="head_is_self" value="1" @checked($headSelf)> This person is the family head
                    </label>
                    @if ($headId && ! $isHead)
                        <p class="help -mt-2 mb-3">The head on file is {{ $family['head_of_family']['full_name'] ?? 'another member' }}. Ticking this makes this person the head instead.</p>
                    @endif

                    @if ($puroks->isNotEmpty())
                        <div class="mb-3">
                            <label for="sent-purok" class="label-sm">Purok</label>
                            <select id="sent-purok" name="purok_id" class="input">
                                <option value="">Not recorded</option>
                                @foreach ($puroks as $p)
                                    <option value="{{ $p->remote_id }}" @selected((string) $purok === (string) $p->remote_id)>{{ $p->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div>
                        <label for="sent-single" class="label-sm">Only one family head? (single-headed)</label>
                        <select id="sent-single" name="is_single_headed" class="input">
                            <option value="" @selected($single === '')>Not yet known</option>
                            <option value="1" @selected($single === '1')>Yes</option>
                            <option value="0" @selected($single === '0')>No</option>
                        </select>
                    </div>
                </fieldset>

                <div class="entry-section" role="group" aria-label="About the actual family head" data-other-head>
                    <div class="border border-dashed border-gray-400 bg-gray-50 p-3">
                        <p class="text-xs font-semibold text-gray-800">About the actual family head</p>
                        <p class="text-xs text-gray-600 mt-0.5 mb-2">Only when the head is someone else and not yet registered.</p>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="sent-head-sex" class="label-sm">Head's sex</label>
                                <select id="sent-head-sex" name="head_sex" class="input">
                                    <option value="" @selected($headSex === '')>Not yet known</option>
                                    <option value="male" @selected($headSex === 'male')>Male</option>
                                    <option value="female" @selected($headSex === 'female')>Female</option>
                                </select>
                            </div>
                            <div>
                                <label for="sent-head-minor" class="label-sm">Head is a minor?</label>
                                <select id="sent-head-minor" name="head_is_minor" class="input">
                                    <option value="" @selected($headMinor === '')>Not yet known</option>
                                    <option value="1" @selected($headMinor === '1')>Yes (under 18)</option>
                                    <option value="0" @selected($headMinor === '0')>No</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <fieldset class="entry-section">
                    <legend class="entry-section-title">Sectoral details</legend>
                    <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm text-gray-700">
                        @foreach (\App\Models\EcBoardEntry::SECTORAL_FLAGS as $name => [$label])
                            <label class="flex items-center gap-2" @if (in_array($name, \App\Models\EcBoardEntry::FEMALE_ONLY_FLAGS, true)) data-female-only @endif>
                                <input type="checkbox" name="{{ $name }}" value="1" @checked($flag($name))> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <p class="help mt-2">Pregnant and lactating apply to a female only.</p>
                </fieldset>

                <div class="flex flex-wrap items-center gap-3 mt-4">
                    <button type="submit" class="btn btn-primary" data-online-only>Save on the server</button>
                    <a href="{{ route('sync.index') }}#sent" class="btn btn-secondary">Cancel</a>
                    <p class="text-sm text-amber-800" data-offline-only hidden>{{ \App\Http\Controllers\SentRecordController::OFFLINE }}</p>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const root = document.querySelector('[data-sent-edit]');
        const self = root.querySelector('[name="head_is_self"]');
        const other = root.querySelector('[data-other-head]');
        const sex = root.querySelector('[name="sex"]');
        const offline = root.querySelector('[data-offline-only]');
        const apply = () => {
            other.hidden = self.checked;
            root.querySelectorAll('[data-female-only]').forEach((label) => {
                label.hidden = sex.value === 'male';
                if (sex.value === 'male') label.querySelector('input').checked = false;
            });
            offline.hidden = navigator.onLine;
        };
        [self, sex].forEach((el) => el.addEventListener('change', apply));
        window.addEventListener('online', apply);
        window.addEventListener('offline', apply);
        apply();
    });
</script>
@endsection
