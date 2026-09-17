@extends('layouts.app')

@section('title', $center->name)
@section('nav-evacuation-centers', 'active')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <a href="{{ route('evacuation-centers.index') }}" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1 mb-2">
                <i class="ti ti-arrow-left" style="font-size: 12px;" aria-hidden="true"></i> All centers
            </a>
            <h1 class="text-xl font-bold text-brand mb-1">{{ $center->name }}</h1>
            <p class="text-sm text-gray-500">{{ $barangayName }}</p>
        </div>

        @if ($events->isNotEmpty())
            <form method="GET" action="{{ route('evacuation-centers.show', $center) }}">
                <select name="event" onchange="this.form.submit()" class="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white text-gray-700">
                    @foreach ($events as $e)
                        <option value="{{ $e->id }}" @selected($selectedEventId === $e->id)>{{ $e->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>

    @if ($events->isEmpty())
        <div class="bg-amber-50 border border-amber-100 rounded-2xl p-4 mb-6 text-sm text-amber-800">
            No disaster events cached on this device -- refresh reference data while online before adding evacuees here.
        </div>
    @else
        <div class="grid grid-cols-2 gap-4 mb-6">
            <div class="card-modern p-4">
                <div class="flex items-center gap-2 mb-3">
                    <i class="ti ti-cloud-check text-gray-400" style="font-size: 16px;" aria-hidden="true"></i>
                    <p class="text-sm font-bold text-gray-700">As of last sync</p>
                </div>
                <p class="text-xs text-gray-400 mb-3">The central server's own live tally, refreshed just now if this device is online -- otherwise showing whatever was last fetched for this center and event.</p>
                @include('evacuation-centers._breakdown_table', ['matrix' => $lastKnownBreakdown, 'ageBrackets' => $breakdownAgeBrackets])
            </div>
            <div class="card-modern p-4">
                <div class="flex items-center gap-2 mb-3">
                    <i class="ti ti-device-desktop text-amber-500" style="font-size: 16px;" aria-hidden="true"></i>
                    <p class="text-sm font-bold text-gray-700">Added on this device (pending sync)</p>
                </div>
                <p class="text-xs text-gray-400 mb-3">Only this device's own not-yet-synced entries for this event. Other devices may have their own pending entries not reflected here until everything reaches the central server.</p>
                @include('evacuation-centers._breakdown_table', ['matrix' => $pendingBreakdown, 'ageBrackets' => $breakdownAgeBrackets])
            </div>
        </div>

        <div class="card-modern p-4 mb-6" data-ec-board-entry-form>
            <h2 class="text-sm font-bold text-gray-700 mb-3">Add evacuee</h2>
            <div class="form-errors bg-red-50 text-red-700 text-sm rounded-xl p-3 mb-3" @if (! $errors->any()) style="display: none;" @endif>
                {{ $errors->first() }}
            </div>
            <form method="POST" action="{{ route('ec-board-entries.store', $center) }}" class="flex flex-col gap-4">
                @csrf
                @include('evacuation-centers._entry_fields', ['entry' => null])
                <button type="submit" class="btn-modern btn-primary-modern bg-brand hover:bg-brand-dark text-white text-sm px-4 py-2.5 w-fit">
                    Add evacuee (offline)
                </button>
            </form>
        </div>

        <div>
            <h2 class="text-sm font-bold text-gray-700 mb-3">Pending entries for this event</h2>
            @if ($pendingEntries->isEmpty())
                <p class="text-sm text-gray-400">Nothing waiting to sync for this event.</p>
            @else
                <div class="flex flex-col gap-3">
                    @foreach ($pendingEntries as $entry)
                        <div class="card-modern p-4">
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="font-bold text-sm text-gray-800">{{ $entry->householdLabel() }}</p>
                                    <p class="text-xs text-gray-500 mt-0.5">
                                        {{ \Illuminate\Support\Str::headline($entry->sex) }} &middot;
                                        {{ $ageBrackets[$entry->age_bracket] ?? $entry->age_bracket }}
                                    </p>
                                    <p class="text-xs text-gray-400 mt-1">Added {{ $entry->created_at->format('M j, Y g:i A') }}</p>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <span class="flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-full bg-amber-50 text-amber-700">
                                        <i class="ti ti-clock" style="font-size: 12px;" aria-hidden="true"></i> Waiting to sync
                                    </span>
                                    <a href="{{ route('ec-board-entries.edit', $entry) }}" data-modal-trigger="ec-board-entry" class="w-7 h-7 rounded-full flex items-center justify-center text-gray-400 hover:text-brand hover:bg-gray-100" aria-label="Edit" title="Edit">
                                        <i class="ti ti-pencil" style="font-size: 14px;" aria-hidden="true"></i>
                                    </a>
                                    <form method="POST" action="{{ route('ec-board-entries.destroy', $entry) }}" onsubmit="if (!confirm('Remove this pending entry from this device? This cannot be undone.')) { event.stopPropagation(); return false; }">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="w-7 h-7 rounded-full flex items-center justify-center text-gray-400 hover:text-red-600 hover:bg-red-50" aria-label="Delete" title="Delete">
                                            <i class="ti ti-trash" style="font-size: 14px;" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            @if ($entry->sync_error)
                                <p class="text-xs text-red-500 mt-2 border-t border-gray-100 pt-2">{{ $entry->sync_error }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        window.ELIKAS.initEcBoardEntryForm(document.querySelector('[data-ec-board-entry-form]'));
    });
</script>
@endsection
