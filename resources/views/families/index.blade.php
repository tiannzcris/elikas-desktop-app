@extends('layouts.app')

@section('title', 'Registered families')
@section('nav-families', 'active')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-brand mb-1">Registered families</h1>
            <p class="text-sm text-gray-500">Everything registered on this device, synced or not.</p>
        </div>
        <div class="flex gap-3">
            <form method="POST" action="{{ route('families.sync') }}">
                @csrf
                <button type="submit" class="btn-modern flex items-center gap-1.5 bg-white border border-gray-200 hover:bg-gray-50 text-sm text-gray-700 px-4 py-2.5">
                    <i class="ti ti-cloud-upload" style="font-size: 15px;" aria-hidden="true"></i> Sync now
                </button>
            </form>
            <a href="{{ route('families.create') }}" data-modal-trigger="register-family" class="btn-modern btn-primary-modern flex items-center gap-1.5 bg-brand hover:bg-brand-dark text-white text-sm px-4 py-2.5">
                <i class="ti ti-user-plus" style="font-size: 15px;" aria-hidden="true"></i> Register a family
            </a>
        </div>
    </div>

    @if ($families->isEmpty())
        <div class="flex flex-col items-center text-center py-16">
            <i class="ti ti-users text-gray-300 mb-3" style="font-size: 40px;" aria-hidden="true"></i>
            <p class="text-sm text-gray-400">No families registered on this device yet.</p>
        </div>
    @else
        <div class="flex flex-col gap-3">
            @foreach ($families as $family)
                <div class="card-modern p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="font-bold text-sm text-gray-800">{{ $family->barangay->name ?? 'Unknown barangay' }}</p>
                            <p class="text-xs text-gray-500 mt-0.5">
                                {{ $family->evacuationEvent->name ?? '' }} &middot;
                                {{ $family->evacuees->count() }} member(s) &middot;
                                {{ $family->displacement_type === 'inside_center' ? 'Inside center' : 'Outside center' }}
                            </p>
                            <p class="text-xs text-gray-400 mt-1">Registered {{ $family->created_at->format('M j, Y g:i A') }}</p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            @if ($family->synced_at)
                                <span class="flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-full bg-green-50 text-green-700">
                                    <i class="ti ti-check" style="font-size: 12px;" aria-hidden="true"></i> Synced
                                </span>
                            @else
                                <span class="flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-full bg-amber-50 text-amber-700">
                                    <i class="ti ti-clock" style="font-size: 12px;" aria-hidden="true"></i> Waiting to sync
                                </span>
                                {{-- Editing/removing only ever makes sense for a not-yet-synced record --
                                     once it's on the central server, this device's copy is just a local
                                     staging record of what was submitted, not something to keep changing. --}}
                                <a href="{{ route('families.edit', $family) }}" data-modal-trigger="register-family" class="w-7 h-7 rounded-full flex items-center justify-center text-gray-400 hover:text-brand hover:bg-gray-100" aria-label="Edit" title="Edit">
                                    <i class="ti ti-pencil" style="font-size: 14px;" aria-hidden="true"></i>
                                </a>
                                {{-- Explicit stopPropagation() on cancel, not just "return false" -- the
                                     shared page-transition listener in app.js fades #main out on ANY submit
                                     event that reaches document, regardless of whether this handler's return
                                     value cancelled the actual submission. Without stopping propagation here,
                                     clicking "Cancel" would still fade the whole page out with nothing
                                     submitted to bring it back. --}}
                                <form method="POST" action="{{ route('families.destroy', $family) }}" onsubmit="if (!confirm('Remove this pending registration from this device? This cannot be undone.')) { event.stopPropagation(); return false; }">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="w-7 h-7 rounded-full flex items-center justify-center text-gray-400 hover:text-red-600 hover:bg-red-50" aria-label="Delete" title="Delete">
                                        <i class="ti ti-trash" style="font-size: 14px;" aria-hidden="true"></i>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                    @if ($family->sync_error)
                        <p class="text-xs text-red-500 mt-2 border-t border-gray-100 pt-2">{{ $family->sync_error }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
@endsection
