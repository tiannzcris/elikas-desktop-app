@extends('layouts.app')

@section('title', 'Print and download')
@section('nav-downloads', 'active')

@section('content')
    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Print and download</h1>
            <p class="page-subtitle flex flex-wrap items-center gap-2">
                Barangay {{ $currentUser->barangay_name }}
                @if ($event)<span aria-hidden="true">·</span> <x-event-name :event="$event" />@endif
            </p>
        </div>
    </div>

    <div class="callout callout-warning flex items-start gap-3 mb-6" role="note">
        <i class="ti ti-shield-lock shrink-0 mt-0.5" style="font-size: 18px;" aria-hidden="true"></i>
        <p>These files are made on this computer from the data it holds, online or not, and say "Generated offline, as of …". They contain personal information: keep them secure, and delete copies you no longer need. Each file saved is recorded.</p>
    </div>

    @if (! $event)
        <x-lock-notice :message="\App\Models\EvacuationEvent::NO_OPEN_EVENT" class="mb-6" />
    @else
        <div class="grid gap-4 lg:grid-cols-2 mb-8">
            @foreach ($documents as $key => $doc)
                <section class="card card-body flex flex-col gap-3" data-document="{{ $key }}">
                    <h2 class="section-title">{{ $doc['label'] }}</h2>
                    <p class="text-sm text-gray-700">
                        @switch($key)
                            @case('ec-board') One center's board: families and persons, age and sex, sectoral groups, and what this computer has not synced yet. @break
                            @case('family-list') Every family at your centers and in private houses, with where they stay. @break
                            @case('relief-sheet') A sheet to hand out relief at one center: each family, boxes, date and a signature line. @break
                            @case('families-to-evacuate') The list per purok, high-risk puroks first, with who is checked in. @break
                        @endswitch
                    </p>
                    @foreach ($doc['formats'] as $format)
                        <form method="POST" action="{{ route('downloads.save') }}" class="flex flex-wrap items-end gap-2"
                            data-confirm-title="Save {{ $doc['label'] }} ({{ strtoupper($format === 'xlsx' ? 'Excel' : 'PDF') }})?"
                            data-confirm="This file contains personal information. Keep it secure. You choose where it is saved; it is recorded on this computer."
                            data-confirm-label="Save file">
                            @csrf
                            <input type="hidden" name="document" value="{{ $key }}">
                            <input type="hidden" name="format" value="{{ $format }}">
                            @if ($doc['per_center'])
                                <div class="flex-1 min-w-[12rem]">
                                    <label for="center-{{ $key }}-{{ $format }}" class="label">Center</label>
                                    <select id="center-{{ $key }}-{{ $format }}" name="center" class="input" required>
                                        @foreach ($centers as $c)
                                            <option value="{{ $c->id }}" @selected($selectedCenter === $c->id)>{{ $c->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            <button type="submit" class="btn btn-secondary" data-save-document="{{ $key }}-{{ $format }}">
                                <i class="ti {{ $format === 'pdf' ? 'ti-file-type-pdf' : 'ti-file-spreadsheet' }}" aria-hidden="true"></i>
                                {{ $format === 'pdf' ? 'Save as PDF' : 'Save as Excel' }}
                            </button>
                        </form>
                    @endforeach
                </section>
            @endforeach
        </div>
    @endif

    <section class="card" aria-labelledby="recent-title">
        <div class="card-body">
            <div class="card-header">
                <h2 id="recent-title" class="card-title">Files saved from this computer</h2>
                <span class="text-sm text-gray-600">Recorded here; not yet reported to the server.</span>
            </div>
            @if ($recent->isEmpty())
                <x-empty icon="ti-file-off" title="No file saved yet." compact />
            @else
                <table class="data-table" data-download-log>
                    <thead><tr><th>When</th><th>File</th><th>By</th><th>Where</th></tr></thead>
                    <tbody>
                        @foreach ($recent as $log)
                            <tr>
                                <td>{{ \App\Support\Format::when($log->created_at) }}</td>
                                <td class="text-gray-900 break-all">{{ $log->file_name }}</td>
                                <td>{{ $log->user_name }}</td>
                                <td class="text-sm text-gray-700 break-all">{{ $log->saved_to ?? 'Browser download' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>
@endsection
