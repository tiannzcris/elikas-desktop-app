@extends('layouts.app')

@section('title', 'Sync')
@section('nav-sync', 'active')

@section('content')
    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Sync</h1>
        </div>
        <div class="page-actions">
            @include('partials._sync_button', ['primary' => true])
        </div>
    </div>

    <div class="stat-strip grid-cols-3 mb-6">
        <div class="stat">
            <p class="stat-label">Waiting to send</p>
            <p class="stat-value" data-sync-waiting>{{ number_format($waiting->count()) }}</p>
        </div>
        <div class="stat {{ $attention->isNotEmpty() ? 'stat-alert' : '' }}">
            <p class="stat-label">Needs attention</p>
            <p class="stat-value">{{ number_format($attention->count()) }}</p>
        </div>
        <div class="stat">
            <p class="stat-label">Last sent</p>
            <p class="stat-value text-base leading-8">
                @if ($lastSyncAt)
                    <time datetime="{{ $lastSyncAt }}" data-local-time>{{ \App\Support\Format::when($lastSyncAt) }}</time>
                @else
                    Not yet
                @endif
            </p>
        </div>
    </div>

    <div class="tabs mb-6" role="tablist" data-tabs>
        <button type="button" class="tab" role="tab" data-tab="to-send">To send ({{ number_format($waiting->count() + $attention->count()) }})</button>
        <button type="button" class="tab" role="tab" data-tab="sent">Sent ({{ number_format($sent->count()) }})</button>
    </div>

    <div data-tab-panel="to-send">
    @if ($attention->isNotEmpty())
        <section class="card mb-6" aria-labelledby="attention-title">
            <div class="card-body">
                <div class="card-header">
                    <h2 id="attention-title" class="card-title">Needs attention</h2>
                </div>
                <table class="data-table">
                    <thead><tr><th>Record</th><th>Center</th><th>Why</th><th class="cell-actions"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @foreach ($attention as $item)
                            <tr data-sync-item="{{ $item['key'] }}">
                                <td class="font-medium text-gray-900">{{ $item['label'] }}</td>
                                <td>{{ $item['place'] }}</td>
                                <td>
                                    @php
                                        [$stateLabel, $stateTone] = match ($item['model']->sync_state) {
                                            'decision' => ['Decide', 'badge-warning'],
                                            'event_closed' => ['Event closed', 'badge-lock'],
                                            'event_locked' => ['Waiting for the evacuation', 'badge-lock'],
                                            default => ['Refused', 'badge-danger'],
                                        };
                                    @endphp
                                    <span class="badge {{ $stateTone }}" data-sync-state="{{ $item['model']->sync_state }}">@if ($stateTone === 'badge-lock')<i class="ti ti-lock" aria-hidden="true"></i> @endif{{ $stateLabel }}</span>
                                    <span class="text-sm text-gray-700">{{ $item['model']->sync_error }}</span>
                                    @if ($item['model']->sync_state === 'event_locked')
                                        <p class="text-sm text-gray-600 mt-1">Kept on this computer. It goes by itself once CSWD starts the evacuation.</p>
                                    @endif
                                    @if ($item['kind'] === 'returning' && $item['model']->sync_state === 'decision')
                                        {{-- The server's possible duplicates: the same family, or a different one? --}}
                                        <div class="mt-3 flex flex-col gap-2" data-duplicate-choices>
                                            @foreach ($item['model']->possible_duplicates ?? [] as $match)
                                                <form method="POST" action="{{ route('sync.decide', $item['model']) }}" class="flex flex-wrap items-center gap-2">
                                                    @csrf
                                                    <input type="hidden" name="decision" value="link">
                                                    <input type="hidden" name="family_id" value="{{ $match['family_id'] }}">
                                                    <span class="text-sm text-gray-900">
                                                        {{ $match['name'] ?? $match['head_name'] ?? 'Family #'.$match['family_id'] }}
                                                        <span class="text-gray-600">· {{ \App\Support\Plural::of($match['member_count'] ?? 0, 'person', 'people') }}{{ ! empty($match['evacuation_center']) ? ' · '.$match['evacuation_center']['name'] : '' }}</span>
                                                    </span>
                                                    <button type="submit" class="btn btn-secondary btn-sm">Same family, link it</button>
                                                </form>
                                            @endforeach
                                            <form method="POST" action="{{ route('sync.decide', $item['model']) }}">
                                                @csrf
                                                <input type="hidden" name="decision" value="different">
                                                <button type="submit" class="btn btn-secondary btn-sm">Different family, add as new</button>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                                <td class="cell-actions">
                                    <div class="flex justify-end gap-2">
                                        @if (in_array($item['model']->sync_state, ['failed', 'event_closed'], true))
                                            <form method="POST" action="{{ route('sync.retry') }}">
                                                @csrf
                                                <input type="hidden" name="key" value="{{ $item['key'] }}">
                                                <button type="submit" class="btn btn-secondary btn-sm">Try again</button>
                                            </form>
                                        @endif
                                        @php
                                            $removeUrl = match ($item['kind']) {
                                                'returning' => route('sync.forget-returning', $item['model']),
                                                'entry' => route('ec-board-entries.destroy', $item['model']),
                                                default => null,
                                            };
                                        @endphp
                                        @if ($removeUrl)
                                            <x-row-menu label="More actions">
                                                <form method="POST" action="{{ $removeUrl }}" data-confirm-title="Remove from this device?"
                                                    data-confirm="The server never accepted it. Cannot be undone." data-confirm-label="Remove" data-confirm-tone="danger">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="menu-item menu-item-danger" role="menuitem">
                                                        <i class="ti ti-trash" style="font-size: 15px;" aria-hidden="true"></i> Remove
                                                    </button>
                                                </form>
                                            </x-row-menu>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="card" aria-labelledby="waiting-title">
        <div class="card-body">
            <div class="card-header">
                <h2 id="waiting-title" class="card-title">Waiting to send</h2>
            </div>
            @if ($waiting->isEmpty())
                <x-empty icon="ti-cloud-check" title="Everything on this device has been sent." text="New entries go out by themselves whenever the server can be reached." compact />
            @else
                <table class="data-table">
                    <thead><tr><th>Record</th><th>Center</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($waiting as $item)
                            <tr data-sync-item="{{ $item['key'] }}">
                                <td class="font-medium text-gray-900">{{ $item['label'] }}</td>
                                <td>{{ $item['place'] }}</td>
                                <td class="text-sm text-gray-700">
                                    @if ($item['blockedBy'])
                                        Waits for family {{ $item['blockedBy']->displayName() }}
                                    @elseif ($item['model']->sync_error)
                                        Not sent yet: {{ $item['model']->sync_error }}
                                    @else
                                        Ready
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>
    </div>

    {{-- What this device sent for the selected event, newest first. A sent
         registration is corrected on the server (online); other kinds have
         no correction there and say where they can be seen. --}}
    <section class="card" data-tab-panel="sent" aria-labelledby="sent-title" data-sent-list>
        <div class="card-body">
            <div class="card-header">
                <h2 id="sent-title" class="card-title">Sent{{ $working ? ' for '.$working->name : '' }}</h2>
            </div>
            <p class="text-sm text-gray-600 mb-3">What this computer sent in the last {{ \App\Services\SyncService::SENT_DAYS }} days (at most {{ \App\Services\SyncService::SENT_LIMIT }}), newest first. It is on the server: CSWD sees it, and it counts in every figure.</p>
            @if ($sent->isEmpty())
                <x-empty icon="ti-send" title="Nothing sent for this event from this computer yet." text="Records go out by themselves whenever the server can be reached, and are listed here." compact />
            @else
                <table class="data-table">
                    <thead><tr><th>Record</th><th>Where</th><th>Sent</th><th>By</th><th class="cell-actions"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @foreach ($sent as $item)
                            <tr data-sent-item="{{ $item['key'] }}">
                                <td class="font-medium text-gray-900">{{ $item['label'] }}</td>
                                <td>{{ $item['place'] }}</td>
                                <td class="whitespace-nowrap"><time datetime="{{ $item['sentAt']->toIso8601String() }}" data-local-time>{{ \App\Support\Format::when($item['sentAt']) }}</time></td>
                                <td>{{ $item['sentBy'] ?? '–' }}</td>
                                <td class="cell-actions">
                                    @if ($item['editable'])
                                        <a href="{{ route('sync.sent.edit', $item['model']) }}" class="btn btn-secondary btn-sm" data-online-link data-offline-message="{{ \App\Http\Controllers\SentRecordController::OFFLINE }}" data-sent-edit-link>Edit</a>
                                    @else
                                        @php [$where, $url, $onWeb] = $item['seeItAt']; @endphp
                                        <span class="text-sm text-gray-600" data-see-it-at>
                                            See it in
                                            @if ($url)
                                                <a href="{{ $url }}" class="link">{{ $where }}</a>
                                            @else
                                                {{ $where }}@if ($onWeb) <span class="text-gray-500">({{ $webUrl }})</span>@endif
                                            @endif
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>
@endsection
