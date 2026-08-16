@extends('layouts.app')

@section('title', 'Register a family')
@section('nav-register', 'active')

@section('content')
    <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto py-10 px-4" style="background: rgba(15, 36, 71, 0.55);">
        <div class="modal-pop w-full max-w-3xl bg-white rounded-2xl shadow-2xl">
            <div class="flex items-start justify-between px-6 pt-6">
                <div>
                    <h1 class="text-xl font-bold text-brand mb-1">Register a family</h1>
                    <p class="text-sm text-gray-500">Saved to this device immediately -- no internet needed. Sync later when you're back online.</p>
                </div>
                <a href="{{ route('dashboard') }}" class="text-gray-400 hover:text-gray-600 shrink-0" aria-label="Close">
                    <i class="ti ti-x" style="font-size: 20px;" aria-hidden="true"></i>
                </a>
            </div>

            @if ($errors->any())
                <div class="mx-6 mt-4 bg-red-50 text-red-700 text-sm rounded-lg p-3">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('families.store') }}" class="flex flex-col gap-4 p-6">
                @csrf
                <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm text-gray-600 block mb-1">Barangay</label>
                        <select name="barangay_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            <option value="">Select barangay</option>
                            @foreach ($barangays as $b)
                                <option value="{{ $b->id }}" data-remote-id="{{ $b->remote_id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600 block mb-1">Disaster event</label>
                        <select name="evacuation_event_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            <option value="">Select event</option>
                            @foreach ($events as $e)
                                <option value="{{ $e->id }}">{{ $e->name }}</option>
                            @endforeach
                        </select>
                        @if ($events->isEmpty())
                            <p class="text-xs text-amber-600 mt-1">No events cached -- refresh reference data from the dashboard while online.</p>
                        @endif
                    </div>
                    <div>
                        <label class="text-sm text-gray-600 block mb-1">Displacement type</label>
                        <select name="displacement_type" id="displacement_type" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            <option value="inside_center">Inside an evacuation center</option>
                            <option value="outside_center">Outside (evacuated to relatives/other location)</option>
                        </select>
                    </div>
                    <div id="center-field">
                        <label class="text-sm text-gray-600 block mb-1">Evacuation center</label>
                        <select name="evacuation_center_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            <option value="">Select center</option>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">Populated once you pick a barangay above.</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-600 col-span-2">
                        <input type="checkbox" name="is_4ps_beneficiary" value="1"> Household is a 4Ps beneficiary
                    </label>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-sm font-medium text-gray-700">Household members</h2>
                        <button type="button" id="add-member-btn" class="text-sm text-brand hover:underline">+ Add another member</button>
                    </div>
                    <div id="members-container" class="flex flex-col gap-4"></div>
                </div>

                <button type="submit" class="bg-brand hover:bg-brand-dark text-white text-sm font-bold rounded-lg px-4 py-2.5 w-fit">
                    Save family (offline)
                </button>
            </form>
        </div>
    </div>
@endsection

@section('scripts')
<script>
    // All barangays' centers are cached locally (small dataset for one
    // city), so this filters client-side rather than needing a server
    // round-trip -- works identically whether the device is online or not.
    const allCenters = @json($centers ?? []);

    let memberCount = 0;

    function memberRowHtml(index) {
        return `
        <div class="member-row bg-white border border-gray-200 rounded-xl p-4" data-index="${index}">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-medium text-gray-600">Member ${index + 1}</p>
                ${index > 0 ? `<button type="button" class="remove-member text-xs text-red-500 hover:underline">Remove</button>` : ''}
            </div>
            <div class="grid grid-cols-3 gap-3">
                <input type="text" name="members[${index}][first_name]" placeholder="First name" class="border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
                <input type="text" name="members[${index}][middle_name]" placeholder="Middle name" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <input type="text" name="members[${index}][last_name]" placeholder="Last name" class="border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
                <select name="members[${index}][sex]" class="border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
                    <option value="">Sex</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                </select>
                <input type="date" name="members[${index}][date_of_birth]" class="border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
                <input type="text" name="members[${index}][contact_number]" placeholder="Contact number" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="flex flex-wrap gap-4 mt-3 text-xs text-gray-600 items-center">
                <input type="hidden" name="members[${index}][is_head_of_family]" value="0">
                <label class="flex items-center gap-1.5"><input type="radio" name="members[${index}][is_head_of_family]" value="1"> Head of family</label>
                <label class="flex items-center gap-1.5"><input type="checkbox" class="m-is_pwd" name="members[${index}][is_pwd]" value="1"> PWD</label>
                <input type="text" placeholder="PWD type" class="m-pwd_type hidden border border-gray-300 rounded-lg px-2 py-1 text-xs" name="members[${index}][pwd_type]">
                <label class="flex items-center gap-1.5"><input type="checkbox" name="members[${index}][is_pregnant]" value="1"> Pregnant</label>
                <label class="flex items-center gap-1.5"><input type="checkbox" name="members[${index}][is_lactating]" value="1"> Lactating</label>
                <label class="flex items-center gap-1.5"><input type="checkbox" name="members[${index}][is_solo_parent]" value="1"> Solo parent</label>
                <label class="flex items-center gap-1.5"><input type="checkbox" name="members[${index}][is_indigenous_person]" value="1"> Indigenous person</label>
            </div>
        </div>`;
    }

    function addMemberRow() {
        document.getElementById('members-container').insertAdjacentHTML('beforeend', memberRowHtml(memberCount));
        memberCount++;
    }

    document.getElementById('add-member-btn').addEventListener('click', addMemberRow);
    addMemberRow();

    document.getElementById('members-container').addEventListener('click', (e) => {
        if (e.target.classList.contains('remove-member')) {
            e.target.closest('.member-row').remove();
        }
    });

    document.getElementById('members-container').addEventListener('change', (e) => {
        if (e.target.classList.contains('m-is_pwd')) {
            const pwdTypeInput = e.target.closest('.member-row').querySelector('.m-pwd_type');
            pwdTypeInput.classList.toggle('hidden', ! e.target.checked);
        }
    });

    document.getElementById('displacement_type').addEventListener('change', (e) => {
        document.getElementById('center-field').style.display = e.target.value === 'inside_center' ? 'block' : 'none';
    });

    document.querySelector('[name="barangay_id"]').addEventListener('change', (e) => {
        const selectedOption = e.target.options[e.target.selectedIndex];
        const barangayRemoteId = Number(selectedOption.dataset.remoteId);
        const select = document.querySelector('[name="evacuation_center_id"]');
        const filtered = allCenters.filter((c) => c.barangay_remote_id === barangayRemoteId);
        select.innerHTML = '<option value="">Select center</option>' +
            filtered.map((c) => `<option value="${c.id}">${c.name}</option>`).join('');
    });
</script>
@endsection
