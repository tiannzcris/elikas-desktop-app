{{-- Shared by the drill-down's family-list level and global search results
     -- identical card markup either way, the only difference is which
     $families collection was passed in and what to say when it's empty. --}}
@if ($families->isEmpty())
    <div class="flex flex-col items-center text-center py-16">
        <i class="ti ti-users text-gray-300 mb-3" style="font-size: 40px;" aria-hidden="true"></i>
        <p class="text-sm text-gray-400">{{ $emptyMessage }}</p>
    </div>
@else
    <div class="flex flex-col gap-3">
        @foreach ($families as $family)
            <div class="card-modern p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="font-bold text-sm text-gray-800">{{ $family->headOfFamilyName() }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">
                            {{ $family->barangay->name ?? 'Unknown barangay' }} &middot;
                            {{ $family->evacuationEvent->name ?? '' }} &middot;
                            {{ $family->evacuees->count() }} member(s) &middot;
                            {{ $family->displacement_type === 'inside_center' ? ($family->evacuationCenter->name ?? 'Inside center') : 'Outside center' }}
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

                {{-- A household added through Add Evacuee carries its own
                     head answers -- the source of its Child-/Single-Headed
                     Family counts. Same wording as the web dashboard's
                     family page. --}}
                @if ($family->created_via_ec_board)
                    @php
                        $yesNo = fn (?bool $v) => $v === null ? 'not yet known' : ($v ? 'yes' : 'no');
                        $headSex = $family->headSex();
                    @endphp
                    <p class="text-xs text-gray-500 mt-2 border-t border-gray-100 pt-2">
                        Household: single-headed {{ $yesNo($family->isSingleHeaded()) }}, child-headed {{ $yesNo($family->isChildHeaded()) }}{{ $headSex ? " ({$headSex})" : '' }}
                    </p>
                    @unless ($family->hasLinkedHead())
                        @php
                            $answered = array_filter([$family->head_sex, $family->head_is_minor === null ? null : ($family->head_is_minor ? 'a minor' : 'not a minor')]);
                        @endphp
                        <p class="flex items-start gap-1.5 text-xs text-amber-800 bg-amber-50 rounded-lg px-2.5 py-2 mt-2">
                            <i class="ti ti-user-question shrink-0 mt-px" style="font-size: 14px;" aria-hidden="true"></i>
                            <span>
                                Head not yet linked. {{ $answered ? 'Counts use the answers given for the head ('.implode(', ', $answered).')' : 'Nothing is known about the head yet' }} until a member is linked.
                                When the head arrives, add them under "Already here" on
                                @if ($family->evacuationCenter)
                                    <a href="{{ route('evacuation-centers.ec-board', ['center' => $family->evacuationCenter, 'event' => $family->evacuation_event_id]) }}" class="font-semibold underline hover:text-amber-900">this center's EC Board</a>
                                @else
                                    this center's EC Board
                                @endif
                                and tick "This person is the household head".
                            </span>
                        </p>
                    @endunless
                @endif
            </div>
        @endforeach
    </div>
@endif
