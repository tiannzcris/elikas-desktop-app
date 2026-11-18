@extends('layouts.app')

@section('title', 'Move a family')
@section('nav-transfers', 'active')

@section('content')
    <a href="{{ route('transfers.index') }}" class="back-link">
        <i class="ti ti-arrow-left" style="font-size: 14px;" aria-hidden="true"></i> Transfers
    </a>

    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Move a family</h1>
            <p class="page-subtitle">CSWD moves a family to any center, with a reason · Needs a connection</p>
        </div>
    </div>

    @if (! $event || $event->isClosed())
        <div class="callout callout-info" role="status">{{ $event ? "\"{$event->name}\" is closed. A closed event is read-only." : \App\Models\EvacuationEvent::NO_OPEN_EVENT }}</div>
    @else
        <form method="GET" action="{{ route('transfers.city-move') }}" class="card card-body max-w-3xl flex flex-wrap items-end gap-3 mb-6">
            <div class="flex-1 min-w-[18rem]">
                <label for="from-center" class="label">1. The center the family is at</label>
                <select id="from-center" name="from" class="input" required onchange="this.form.submit()">
                    <option value="">Choose…</option>
                    @foreach ($centers as $c)
                        <option value="{{ $c->id }}" @selected($from?->id === $c->id)>{{ $c->name }} · {{ $barangays[$c->barangay_remote_id] ?? '' }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-secondary" data-online-only>Show families</button>
        </form>

        @if ($from)
            @if ($error)
                <p class="callout callout-warning max-w-3xl" role="status">{{ $error }}</p>
            @elseif (empty($families))
                <div class="card max-w-3xl"><x-empty icon="ti-users" title="No family is checked in at {{ $from->name }}." compact /></div>
            @else
                <form method="POST" action="{{ route('transfers.city-move.store') }}" class="card card-body max-w-3xl flex flex-col gap-4" data-city-move
                    data-confirm-title="Move this family?" data-confirm="It moves from {{ $from->name }} to the chosen center now, with your reason in the activity log." data-confirm-label="Move family">
                    @csrf
                    <input type="hidden" name="from_center_id" value="{{ $from->id }}">
                    <input type="hidden" name="family_label" value="" data-family-label>
                    <div>
                        <label for="family" class="label">2. Family</label>
                        <select id="family" name="family_remote_id" class="input" required>
                            <option value="">Choose…</option>
                            @foreach ($families as $f)
                                @php $label = empty($f['is_generic']) ? ($f['name'] ?? $f['head_of_family']['full_name'] ?? 'Family #'.$f['id']) : 'Family #'.$f['id'].' · '.($f['barangay']['name'] ?? ''); @endphp
                                <option value="{{ $f['id'] }}" data-label="{{ $label }}">{{ $label }} · {{ $f['here_count'] ?? 0 }} here</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="to-center" class="label">3. Move to</label>
                        <select id="to-center" name="to_center_id" class="input" required>
                            <option value="">Choose…</option>
                            @foreach ($centers->where('id', '!=', $from->id) as $c)
                                <option value="{{ $c->id }}">{{ $c->name }} · {{ $barangays[$c->barangay_remote_id] ?? '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="reason" class="label">4. Reason</label>
                        <input id="reason" name="reason" class="input" maxlength="500" required value="{{ old('reason') }}" placeholder="e.g. Covered court roof damaged">
                    </div>
                    <div class="form-actions mt-0"><button type="submit" class="btn btn-primary" data-online-only>Move family</button></div>
                </form>
            @endif
        @endif
    @endif
@endsection

@section('scripts')
    <script>
        // The family's name goes with the move, for the message afterwards.
        document.getElementById('family')?.addEventListener('change', (e) => {
            document.querySelector('[data-family-label]').value = e.target.selectedOptions[0]?.dataset.label ?? '';
        });
    </script>
@endsection
