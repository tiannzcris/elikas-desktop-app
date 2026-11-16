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
            <p class="stat-value" data-sync-waiting>{{ $waiting->count() }}</p>
        </div>
        <div class="stat {{ $attention->isNotEmpty() ? 'stat-alert' : '' }}">
            <p class="stat-label">Needs attention</p>
            <p class="stat-value">{{ $attention->count() }}</p>
        </div>
        <div class="stat">
            <p class="stat-label">Last sent</p>
            <p class="stat-value text-base leading-8">
                @if ($lastSyncAt)
                    <time datetime="{{ $lastSyncAt }}" data-local-time>{{ \Illuminate\Support\Carbon::parse($lastSyncAt)->timezone('Asia/Manila')->format('M j, g:i A') }}</time>
                @else
                    Not yet
                @endif
            </p>
        </div>
    </div>

    @if ($attention->isNotEmpty())
        <section class="card mb-6" aria-labelledby="attention-title">
            <div class="card-body">
                <div class="card-header">
                    <h2 id="attention-title" class="card-title">Needs attention</h2>
                </div>
                <table class="data-table">
                    <thead><tr><th>Record</th><th>Center</th><th>Why</th><th class="sr-only">Actions</th></tr></thead>
                    <tbody>
                        @foreach ($attention as $item)
                            <tr data-sync-item="{{ $item['key'] }}">
                                <td class="font-medium text-gray-900">{{ $item['label'] }}</td>
                                <td>{{ $item['place'] }}</td>
                                <td>
                                    <span class="badge {{ $item['model']->sync_state === 'decision' ? 'badge-warning' : 'badge-danger' }}">{{ $item['model']->sync_state === 'decision' ? 'Decide' : 'Refused' }}</span>
                                    <span class="text-sm text-gray-700">{{ $item['model']->sync_error }}</span>
                                </td>
                                <td class="cell-actions">
                                    <div class="flex justify-end gap-2">
                                        @if ($item['model']->sync_state === 'failed')
                                            <form method="POST" action="{{ route('sync.retry') }}">
                                                @csrf
                                                <input type="hidden" name="key" value="{{ $item['key'] }}">
                                                <button type="submit" class="btn btn-secondary btn-sm">Try again</button>
                                            </form>
                                        @endif
                                        @if ($item['kind'] === 'entry')
                                            <form method="POST" action="{{ route('ec-board-entries.destroy', $item['model']) }}" data-confirm="Remove this record from this device? It was never accepted by the server.">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
                                            </form>
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
                <div class="empty-state">
                    <i class="ti ti-cloud-check" aria-hidden="true"></i>
                    <p>Everything on this device has been sent.</p>
                </div>
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
@endsection
