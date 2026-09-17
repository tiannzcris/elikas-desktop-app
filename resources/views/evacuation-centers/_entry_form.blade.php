{{-- Modal chrome for editing a pending EC Board entry -- the "Add Evacuee"
     form itself only ever appears inline on the center detail page (see
     evacuation-centers/show.blade.php), but editing an existing pending
     entry reuses the exact same field partial (_entry_fields.blade.php)
     inside this modal wrapper, mirroring families/_form.blade.php's own
     create/edit reuse. --}}
<div class="modal-pop w-full max-w-xl bg-white rounded-3xl shadow-2xl" data-ec-board-entry-modal>
    <div class="flex items-start justify-between px-6 pt-6">
        <div>
            <h1 class="text-xl font-bold text-brand mb-1">Edit pending evacuee entry</h1>
            <p class="text-sm text-gray-500">Still saved only on this device -- fix what's needed, then sync when you're back online.</p>
        </div>
        <a href="{{ route('evacuation-centers.show', $center) }}" class="modal-close-btn w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 shrink-0" aria-label="Close">
            <i class="ti ti-x" style="font-size: 18px;" aria-hidden="true"></i>
        </a>
    </div>

    <div class="form-errors mx-6 mt-4 bg-red-50 text-red-700 text-sm rounded-xl p-3" @if (! $errors->any()) style="display: none;" @endif>
        {{ $errors->first() }}
    </div>

    <form method="POST" action="{{ route('ec-board-entries.update', $entry) }}" class="flex flex-col gap-4 p-6">
        @csrf
        @method('PUT')
        @include('evacuation-centers._entry_fields')
        <button type="submit" class="btn-modern btn-primary-modern bg-brand hover:bg-brand-dark text-white text-sm px-4 py-2.5 w-fit">
            Save changes (offline)
        </button>
    </form>
</div>
