@extends('layouts.app')

@section('title', 'Report a problem')

@section('content')
    @php
        $reports = collect($mine?->payload['reports'] ?? []);
        $tones = ['new' => 'badge-info', 'in_progress' => 'badge-warning', 'resolved' => 'badge-success'];
    @endphp
    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Report a problem</h1>
            <p class="page-subtitle">To the E-LIKAS administrator, who handles technical problems</p>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <form method="POST" action="{{ route('support.store') }}" enctype="multipart/form-data" class="card card-body flex flex-col gap-4" data-report-form>
            @csrf
            <div>
                <label for="what" class="label">What happened?</label>
                <textarea id="what" name="what_happened" rows="5" minlength="10" maxlength="2000" class="input" required autofocus
                    placeholder="What you did, what you expected, and what happened instead">{{ old('what_happened') }}</textarea>
            </div>
            <div>
                <label for="page" class="label">Screen <span class="font-normal text-gray-600">(optional)</span></label>
                <input id="page" name="page" value="{{ old('page', $page) }}" maxlength="255" class="input" placeholder="For example: EC Board">
            </div>
            <div>
                <label for="screenshot" class="label">Screenshot <span class="font-normal text-gray-600">(optional: PNG, JPG or WEBP, up to 5 MB)</span></label>
                <input id="screenshot" type="file" name="screenshot" accept="image/png,image/jpeg,image/webp" class="input">
                <p class="help">Press <kbd>Windows</kbd> + <kbd>Shift</kbd> + <kbd>S</kbd> to take one, save it, then choose it here.
                    <strong>Do not include evacuees' names or details in the screenshot.</strong></p>
            </div>
            <div class="flex justify-end"><button type="submit" class="btn btn-primary" data-online-only><i class="ti ti-send" aria-hidden="true"></i> Send report</button></div>
        </form>

        <section class="card card-body flex flex-col gap-3" aria-labelledby="mine-title">
            <div class="card-header">
                <h2 id="mine-title" class="card-title">Your reports</h2>
                <form method="POST" action="{{ route('support.refresh') }}">@csrf<button type="submit" class="btn btn-ghost btn-sm" data-online-only><i class="ti ti-refresh" aria-hidden="true"></i> Refresh</button></form>
            </div>
            @if ($reports->isEmpty())
                <x-empty icon="ti-message-check" title="{{ $mine ? 'No reports yet.' : 'Your reports show here after a refresh.' }}" compact />
            @else
                <ul class="divide-y divide-gray-100" data-my-reports>
                    @foreach ($reports as $r)
                        <li class="py-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-sm text-gray-600">{{ \App\Support\Format::when($r['created_at'] ?? null) }}{{ ! empty($r['page']) ? ' · '.$r['page'] : '' }}</span>
                                <span class="badge {{ $tones[$r['status'] ?? ''] ?? 'badge-neutral' }}">{{ $r['status_label'] ?? \Illuminate\Support\Str::headline($r['status'] ?? '') }}</span>
                            </div>
                            <p class="text-gray-900 mt-1">{{ \Illuminate\Support\Str::limit($r['what_happened'] ?? '', 240) }}</p>
                            @if (! empty($r['reply']))
                                <p class="callout callout-info text-sm mt-2">{{ $r['reply'] }}@if (! empty($r['replied_by']['name'] ?? $r['replied_by'] ?? null)) <span class="block text-xs mt-1">{{ $r['replied_by']['name'] ?? $r['replied_by'] }}, {{ \App\Support\Format::when($r['replied_at'] ?? null) }}</span>@endif</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
@endsection
