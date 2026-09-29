{{-- Shared by the drill-down's family-list level and global search results
     -- identical card markup either way, the only difference is which
     $families collection was passed in and what to say when it's empty. --}}
@if ($families->isEmpty())
    <div class="empty-state">
        <i class="ti ti-users" aria-hidden="true"></i>
        <p>{{ $emptyMessage }}</p>
    </div>
@else
    <div class="flex flex-col gap-3">
        @foreach ($families as $family)
            <div class="card p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="font-bold text-sm text-gray-800">{{ $family->headOfFamilyName() }}</p>
                        <p class="text-xs text-gray-600 mt-0.5">
                            {{ $family->barangay->name ?? 'Unknown barangay' }} &middot;
                            {{ $family->evacuationEvent->name ?? '' }} &middot;
                            {{ $family->evacuees->count() }} member(s) &middot;
                            {{ $family->displacement_type === 'inside_center' ? ($family->evacuationCenter->name ?? 'Inside center') : 'Outside center' }}
                        </p>
                        <p class="text-xs text-gray-500 mt-1">Registered {{ $family->created_at->format('M j, Y g:i A') }}</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        @if ($family->synced_at)
                            <span class="badge badge-success">
                                <i class="ti ti-check" style="font-size: 12px;" aria-hidden="true"></i> Synced
                            </span>
                        @else
                            <span class="badge badge-warning">
                                <i class="ti ti-clock" style="font-size: 12px;" aria-hidden="true"></i> Waiting to sync
                            </span>
                            @if ($family->created_via_ec_board)
                                {{-- No full registration behind it for the form to edit --
                                     it's changed or removed through its EC Board entries. --}}
                                @if ($family->evacuationCenter)
                                    <a href="{{ route('evacuation-centers.ec-board', ['center' => $family->evacuationCenter, 'event' => $family->evacuation_event_id]) }}" class="link flex items-center gap-1 text-xs">
                                        Manage on EC Board <i class="ti ti-chevron-right" style="font-size: 12px;" aria-hidden="true"></i>
                                    </a>
                                @endif
                            @else
                                {{-- Editing/removing only ever makes sense for a not-yet-synced record --
                                     once it's on the central server, this device's copy is just a local
                                     staging record of what was submitted, not something to keep changing. --}}
                                <a href="{{ route('families.edit', $family) }}" data-modal-trigger="register-family" class="btn-icon" aria-label="Edit" title="Edit">
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
                                    <button type="submit" class="btn-icon btn-icon-danger" aria-label="Delete" title="Delete">
                                        <i class="ti ti-trash" style="font-size: 14px;" aria-hidden="true"></i>
                                    </button>
                                </form>
                            @endif
                        @endif
                    </div>
                </div>
                @if ($family->sync_error)
                    <p class="text-xs text-red-700 mt-2 border-t border-gray-100 pt-2">{{ $family->sync_error }}</p>
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
                    <p class="text-xs text-gray-600 mt-2 border-t border-gray-100 pt-2">
                        Household: single-headed {{ $yesNo($family->isSingleHeaded()) }}, child-headed {{ $yesNo($family->isChildHeaded()) }}{{ $headSex ? " ({$headSex})" : '' }}
                    </p>
                    @unless ($family->hasLinkedHead())
                        @php
                            $answered = array_filter([$family->head_sex, $family->head_is_minor === null ? null : ($family->head_is_minor ? 'a minor' : 'not a minor')]);
                        @endphp
                        <p class="callout callout-warning flex items-start gap-1.5 text-xs px-2.5 py-2 mt-2">
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
