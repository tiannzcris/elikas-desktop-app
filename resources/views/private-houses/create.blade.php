@extends('layouts.app')

@section('title', 'Add family to private houses')
@section('nav-ec-board', 'active')

@section('content')
    @php $oldMembers = old('members', []); @endphp
    <a href="{{ route('private-houses.index') }}" class="back-link">
        <i class="ti ti-arrow-left" style="font-size: 14px;" aria-hidden="true"></i> Private houses
    </a>

    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Add family to private houses</h1>
            @if ($event)
                <p class="page-subtitle">Saves to: {{ $event->optionLabel() }} · Barangay {{ $currentUser->barangay_name }}</p>
            @endif
        </div>
    </div>

    @if (\App\Support\EventLock::reason($event, \App\Support\EventLock::PRIVATE_HOUSES))
        @include('partials._event_state', ['event' => $event, 'what' => \App\Support\EventLock::PRIVATE_HOUSES])
    @else
        <form method="POST" action="{{ route('private-houses.store') }}" class="card card-body max-w-3xl flex flex-col gap-8" data-private-house-form>
            @csrf

            <fieldset class="flex flex-col gap-4">
                <legend class="section-title mb-2">1. Head of the family</legend>
                <div class="grid gap-4 sm:grid-cols-4">
                    <div class="sm:col-span-1">
                        <label for="head-first" class="label">First name</label>
                        <input id="head-first" name="head[first_name]" value="{{ old('head.first_name') }}" class="input" required autofocus autocomplete="off" @error('head.first_name') aria-invalid="true" @enderror>
                    </div>
                    <div class="sm:col-span-1">
                        <label for="head-last" class="label">Last name</label>
                        <input id="head-last" name="head[last_name]" value="{{ old('head.last_name') }}" class="input" required autocomplete="off" @error('head.last_name') aria-invalid="true" @enderror>
                    </div>
                    <div>
                        <label for="head-sex" class="label">Sex</label>
                        <select id="head-sex" name="head[sex]" class="input" required>
                            <option value="">Choose…</option>
                            <option value="female" @selected(old('head.sex') === 'female')>Female</option>
                            <option value="male" @selected(old('head.sex') === 'male')>Male</option>
                        </select>
                    </div>
                    <div>
                        <label for="head-age" class="label">Age</label>
                        <input id="head-age" name="head[age]" type="number" min="0" max="120" value="{{ old('head.age') }}" class="input" required>
                    </div>
                </div>
            </fieldset>

            <fieldset class="flex flex-col gap-3">
                <legend class="section-title mb-2">2. Other members <span class="text-base font-normal text-gray-600">(optional)</span></legend>
                <div class="flex flex-col gap-3" data-member-rows>
                    @foreach (array_values($oldMembers) as $i => $m)
                        @include('private-houses._member_row', ['i' => $i, 'm' => $m])
                    @endforeach
                </div>
                <template data-member-template>
                    @include('private-houses._member_row', ['i' => '__I__', 'm' => []])
                </template>
                <button type="button" class="btn btn-secondary self-start" data-add-member>
                    <i class="ti ti-user-plus" aria-hidden="true"></i> Add member
                </button>
                <p class="help">Names and ages now; birth dates can be completed later on the web.</p>
            </fieldset>

            <fieldset class="flex flex-col gap-4">
                <legend class="section-title mb-2">3. Where they stay</legend>
                <div>
                    <label for="host-name" class="label">Relative or friend they stay with</label>
                    <input id="host-name" name="host_name" value="{{ old('host_name') }}" class="input" required maxlength="150" @error('host_name') aria-invalid="true" @enderror>
                </div>
                <div class="form-grid">
                    <div>
                        <label for="host-purok" class="label">Purok</label>
                        <select id="host-purok" name="host_purok_id" class="input">
                            <option value="">Not on the list</option>
                            @foreach ($puroks as $p)
                                <option value="{{ $p->id }}" @selected((int) old('host_purok_id') === $p->id)>{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="host-address" class="label">Address</label>
                        <input id="host-address" name="host_address" value="{{ old('host_address') }}" class="input" maxlength="255" placeholder="e.g. Zone 3, near the chapel" @error('host_address') aria-invalid="true" @enderror>
                    </div>
                </div>
                <p class="help">Choose the purok, enter the address, or both.</p>
                <label class="flex items-center gap-2 text-gray-800">
                    <input type="checkbox" name="is_4ps_beneficiary" value="1" @checked(old('is_4ps_beneficiary'))> 4Ps beneficiary family
                </label>
            </fieldset>

            <div class="form-actions mt-0">
                <a href="{{ route('private-houses.index') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Add family (offline)</button>
            </div>
        </form>
    @endif
@endsection

@section('scripts')
    <script>
        // Another member row, numbered after the last; focus its first field.
        document.querySelector('[data-add-member]')?.addEventListener('click', () => {
            const rows = document.querySelector('[data-member-rows]');
            const html = document.querySelector('[data-member-template]').innerHTML.replaceAll('__I__', String(Date.now()));
            rows.insertAdjacentHTML('beforeend', html);
            rows.lastElementChild.querySelector('input')?.focus();
        });
        document.addEventListener('click', (e) => {
            const remove = e.target.closest('[data-remove-member]');
            if (remove) remove.closest('[data-member-row]').remove();
        });
    </script>
@endsection
