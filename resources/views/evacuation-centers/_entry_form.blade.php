{{-- Modal chrome for editing a pending EC Board entry -- the "Add Evacuee"
     form itself only ever appears inline on the center detail page (see
     evacuation-centers/show.blade.php), but editing an existing pending
     entry reuses the exact same field partial (_entry_fields.blade.php)
     inside this modal wrapper, mirroring families/_form.blade.php's own
     create/edit reuse. --}}
<div class="modal modal-pop max-w-xl" data-ec-board-entry-modal>
    <div class="modal-header">
        <div>
            <h1 class="modal-title">Edit pending evacuee entry</h1>
            <p class="text-sm text-gray-600 mt-0.5">Still saved only on this device -- fix what's needed, then sync when you're back online.</p>
        </div>
        <a href="{{ route('evacuation-centers.ec-board', $center) }}" class="modal-close-btn btn-icon" aria-label="Close">
            <i class="ti ti-x" style="font-size: 18px;" aria-hidden="true"></i>
        </a>
    </div>

    <div class="form-errors callout callout-danger mx-6 mt-4" role="alert" @if (! $errors->any()) style="display: none;" @endif>
        {{ $errors->first() }}
    </div>

    <form method="POST" action="{{ route('ec-board-entries.update', $entry) }}" class="flex flex-col px-6 pt-4">
        @csrf
        @method('PUT')
        @include('evacuation-centers._entry_fields', ['submitLabel' => 'Save changes (offline)'])
    </form>
</div>
