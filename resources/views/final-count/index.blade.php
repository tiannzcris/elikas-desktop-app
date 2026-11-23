@extends('layouts.app')

@section('title', 'Final count')
@section('nav-final-count', 'active')

@section('content')
    @php
        $n = fn ($v) => $v === null ? '–' : number_format((int) $v);
        $latest = $count['latest'];
        $statusLabels = ['pending' => ['Waiting for CSWD', 'badge-warning'], 'validated' => ['Validated', 'badge-success'], 'returned' => ['Returned to the barangay', 'badge-danger']];
        [$statusLabel, $statusTone] = $statusLabels[$latest['status'] ?? ''] ?? ['Not submitted yet', 'badge-neutral'];
        $checks = $count['checks'] ?? [];
        $checkLines = collect([
            ($checks['incomplete_families'] ?? 0) > 0 ? \App\Support\Plural::of($checks['incomplete_families'], 'family has', 'families have').' incomplete details' : null,
            ($checks['possible_duplicates'] ?? 0) > 0 ? \App\Support\Plural::of($checks['possible_duplicates'], 'possible duplicate', 'possible duplicates') : null,
        ])->filter();
    @endphp

    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Final count</h1>
            <p class="page-subtitle flex flex-wrap items-center gap-2">
                Barangay {{ $currentUser->barangay_name }}
                @if ($event)<span aria-hidden="true">·</span> <x-event-name :event="$event" />@endif
            </p>
        </div>
        <div class="page-actions">
            <form method="POST" action="{{ route('final-count.refresh') }}">
                @csrf
                <button type="submit" class="btn btn-secondary" data-online-only><i class="ti ti-refresh" aria-hidden="true"></i> Refresh</button>
            </form>
        </div>
    </div>

    @if ($lock)
        <x-lock-notice :message="$lock" class="mb-6" data-event-lock />
    @endif

    @if ($event && ! $count['has_copy'])
        <div class="card"><x-empty icon="ti-cloud-download" title="The final count is not on this device yet." text="Refresh while online; the copy stays readable offline.">
            <form method="POST" action="{{ route('final-count.refresh') }}">@csrf<button type="submit" class="btn btn-secondary" data-online-only><i class="ti ti-refresh" aria-hidden="true"></i> Refresh now</button></form>
        </x-empty></div>
    @elseif ($event)
        <p class="text-sm text-gray-700 mb-4" data-as-of>As of {{ \App\Support\Format::when($count['fetched_at']) }}: the system's count on the central server.</p>

        <div class="stat-strip grid-cols-2 lg:grid-cols-4 mb-6">
            <div class="stat"><p class="stat-label">Families now</p><p class="stat-value">{{ $n($count['families']) }}</p></div>
            <div class="stat"><p class="stat-label">Persons now</p><p class="stat-value">{{ $n($count['persons']) }}</p></div>
            @if ($count['in_centers'])
                <div class="stat"><p class="stat-label">In centers</p><p class="stat-value">{{ $n($count['in_centers']['persons']) }}</p><p class="stat-note">{{ \App\Support\Plural::of($count['in_centers']['families'], 'family', 'families') }}</p></div>
            @endif
            @if ($count['in_private_houses'])
                <div class="stat"><p class="stat-label">In private houses</p><p class="stat-value">{{ $n($count['in_private_houses']['persons']) }}</p><p class="stat-note">{{ \App\Support\Plural::of($count['in_private_houses']['families'], 'family', 'families') }}</p></div>
            @endif
        </div>

        <section class="card mb-6" aria-labelledby="latest-title">
            <div class="card-body">
                <div class="card-header">
                    <h2 id="latest-title" class="card-title">Your latest submission</h2>
                    <span class="badge {{ $statusTone }}" data-submission-status="{{ $latest['status'] ?? 'none' }}">{{ $statusLabel }}</span>
                </div>
                @if ($latest)
                    <p class="text-gray-800">
                        {{ \App\Support\Plural::of($latest['families'] ?? 0, 'family', 'families') }}, {{ \App\Support\Plural::of($latest['persons'] ?? 0, 'person', 'persons') }}
                        @if (! empty($latest['submitted_at'] ?? $latest['created_at'] ?? null)) · submitted {{ \App\Support\Format::when($latest['submitted_at'] ?? $latest['created_at']) }}@endif
                        @if (! empty($latest['submitted_by']['name'])) by {{ $latest['submitted_by']['name'] }}@endif
                    </p>
                    @if ($count['on_behalf'])
                        <p class="callout callout-info mt-3 text-sm" data-on-behalf>{{ $count['on_behalf'] }} You can still submit an updated count.</p>
                    @endif
                    @if (! empty($latest['review_note']))
                        <p class="text-sm text-gray-700 mt-2">CSWD: {{ $latest['review_note'] }}</p>
                    @endif
                @else
                    <p class="text-gray-700">Nothing submitted for this event yet.</p>
                @endif
            </div>
        </section>

        @if ($count['late_total'] > 0)
            <div class="callout callout-warning mb-6" role="status" data-late-entries>
                <span class="font-semibold">{{ \App\Support\Plural::of($count['late_total'], 'late entry', 'late entries') }}, not yet in your final count.</span>
                @php $late = $count['late']; @endphp
                {{ \App\Support\Plural::of($late['families'] ?? 0, 'family', 'families') }} ({{ \App\Support\Plural::of($late['persons'] ?? 0, 'person', 'persons') }}) added{{ ($late['removed_families'] ?? 0) > 0 ? ' and '.\App\Support\Plural::of($late['removed_families'], 'family', 'families').' removed' : '' }} since {{ \App\Support\Format::when($late['since'] ?? null) }}. Submit an updated count to include them.
            </div>
        @endif

        @if ($checkLines->isNotEmpty())
            <section class="card mb-6" aria-labelledby="checks-title" data-checks>
                <div class="card-body">
                    <div class="card-header"><h2 id="checks-title" class="card-title">System checks</h2><span class="text-sm text-gray-600">Warnings only; they never block a submission.</span></div>
                    <ul class="list-disc pl-5 text-gray-800">
                        @foreach ($checkLines as $line)<li>{{ $line }}</li>@endforeach
                    </ul>
                    @foreach ($checks['duplicate_examples'] ?? [] as $dup)
                        <p class="text-sm text-gray-600 mt-1">{{ $dup['name'] ?? '' }}{{ ! empty($dup['date_of_birth']) ? ', born '.\App\Support\Format::day($dup['date_of_birth']) : '' }}: {{ \App\Support\Plural::of($dup['count'] ?? 2, 'record', 'records') }}</p>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($maySubmit && ! $lock)
            <section class="card" aria-labelledby="submit-title">
                <div class="card-body">
                    <div class="card-header"><h2 id="submit-title" class="card-title">{{ $latest ? 'Submit an updated count' : 'Submit the final count' }}</h2></div>
                    @if ($count['nothing_new'])
                        <p class="text-gray-700" data-nothing-new>Your validated count is current: nothing new to submit.</p>
                    @else
                        @if ($unsent > 0)
                            <p class="callout callout-warning mb-4 text-sm" data-unsent-warning>{{ \App\Support\Plural::of($unsent, 'record on this computer is', 'records on this computer are') }} not sent yet and not in this count. Sync first, then refresh.</p>
                        @endif
                        @php
                            $confirm = 'Families: '.$n($count['families']).' · Persons: '.$n($count['persons'])
                                .($count['in_centers'] ? "\nIn centers: ".\App\Support\Plural::of($count['in_centers']['families'], 'family', 'families').', '.\App\Support\Plural::of($count['in_centers']['persons'], 'person', 'persons') : '')
                                .($count['in_private_houses'] ? "\nIn private houses: ".\App\Support\Plural::of($count['in_private_houses']['families'], 'family', 'families').', '.\App\Support\Plural::of($count['in_private_houses']['persons'], 'person', 'persons') : '')
                                .($checkLines->isNotEmpty() ? "\nChecks: ".$checkLines->implode('; ').'.' : '')
                                .($unsent > 0 ? "\n".\App\Support\Plural::of($unsent, 'record', 'records').' on this computer not sent yet.' : '')
                                ."\n\nAfter this, new evacuees are marked as late entries.";
                        @endphp
                        <form method="POST" action="{{ route('final-count.submit') }}" class="flex flex-col gap-4 max-w-2xl"
                            data-confirm-title="Submit the final count of Barangay {{ $currentUser->barangay_name }}?" data-confirm="{{ $confirm }}"
                            data-confirm-label="Submit" data-confirm-cancel="Review first" data-final-count-form>
                            @csrf
                            <div>
                                <label for="fc-note" class="label">Note to CSWD <span class="font-normal text-gray-600">(optional)</span></label>
                                <textarea id="fc-note" name="note" rows="2" maxlength="1000" class="input">{{ old('note') }}</textarea>
                            </div>
                            <div class="flex justify-end"><button type="submit" class="btn btn-primary" data-online-only>{{ $latest ? 'Submit updated count' : 'Submit final count' }}</button></div>
                        </form>
                    @endif
                </div>
            </section>
        @endif
    @endif
@endsection
