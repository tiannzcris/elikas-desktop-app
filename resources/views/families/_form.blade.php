<div class="modal-pop w-full max-w-3xl bg-white rounded-3xl shadow-2xl" data-register-family-modal>
    <div class="flex items-start justify-between px-6 pt-6">
        <div>
            <h1 class="text-xl font-bold text-brand mb-1">Register a family</h1>
            <p class="text-sm text-gray-500">Saved to this device immediately -- no internet needed. Sync later when you're back online.</p>
        </div>
        <a href="{{ route('dashboard') }}" class="modal-close-btn w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 shrink-0" aria-label="Close">
            <i class="ti ti-x" style="font-size: 18px;" aria-hidden="true"></i>
        </a>
    </div>

    <div class="form-errors mx-6 mt-4 bg-red-50 text-red-700 text-sm rounded-xl p-3" @if (! $errors->any()) style="display: none;" @endif>
        {{ $errors->first() }}
    </div>

    <form method="POST" action="{{ route('families.store') }}" class="flex flex-col gap-4 p-6">
        @csrf
        <div class="bg-gray-50 border border-gray-100 rounded-2xl p-4 grid grid-cols-2 gap-4">
            <div>
                <label class="text-sm text-gray-600 block mb-1">Barangay</label>
                <select name="barangay_id" required class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                    <option value="">Select barangay</option>
                    @foreach ($barangays as $b)
                        <option value="{{ $b->id }}" data-remote-id="{{ $b->remote_id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
                <div class="mt-3">
                    <label class="text-sm text-gray-600 block mb-1">Street/Sitio Address <span class="text-gray-400">(optional)</span></label>
                    <input type="text" name="home_address" value="{{ old('home_address') }}" placeholder="e.g. Purok 3, Sitio Malinao" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                </div>
            </div>
            <div>
                <label class="text-sm text-gray-600 block mb-1">Disaster event</label>
                <select name="evacuation_event_id" required class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
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
                <select name="displacement_type" id="displacement_type" required class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                    <option value="inside_center">Inside an evacuation center</option>
                    <option value="outside_center">Outside (evacuated to relatives/other location)</option>
                </select>
            </div>
            <div id="center-field">
                <label class="text-sm text-gray-600 block mb-1">Evacuation center</label>
                <select name="evacuation_center_id" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
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
                <h2 class="text-sm font-bold text-gray-700">Household members</h2>
                <button type="button" id="add-member-btn" class="text-sm text-brand font-semibold hover:underline">+ Add another member</button>
            </div>
            <div id="members-container" class="flex flex-col gap-4"></div>
        </div>

        <button type="submit" class="btn-modern btn-primary-modern bg-brand hover:bg-brand-dark text-white text-sm px-4 py-2.5 w-fit">
            Save family (offline)
        </button>
    </form>
</div>

<script type="application/json" id="register-family-data">{!! json_encode(['centers' => $centers ?? [], 'cachedEvacuees' => $cachedEvacuees ?? [], 'evacueesIndexUrl' => route('evacuees.index')], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}</script>
