@extends('layouts.app')

@section('title', 'Data and security')

@section('content')
    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Data and security</h1>
            <p class="page-subtitle">How this computer protects the personal data it holds</p>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="card card-body flex flex-col gap-3" aria-labelledby="idle-title" data-idle-setting>
            <h2 id="idle-title" class="section-title">Sign out after inactivity</h2>
            <p class="text-sm text-gray-700">Without a key, click or mouse movement for this long, this computer signs you out of the screen. Records waiting to be sent stay. Signing in again works offline.</p>
            <form method="POST" action="{{ route('data-security.timeout') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label for="idle-minutes" class="label">Sign out after</label>
                    <select id="idle-minutes" name="minutes" class="input w-auto">
                        @foreach ($options as $option)
                            <option value="{{ $option }}" @selected($option === $minutes)>{{ \App\Support\Plural::of($option, 'minute', 'minutes') }}{{ $option === \App\Security\IdleSignOut::DEFAULT_MINUTES ? ' (default)' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary">Save</button>
            </form>
        </section>

        <section class="card card-body flex flex-col gap-3" aria-labelledby="enc-title" data-encryption-status>
            <h2 id="enc-title" class="section-title">Encrypted on this computer</h2>
            <p class="text-sm text-gray-700">Names, addresses, family members, the lists copied from the server and your sign-in are stored encrypted, with a key made for this computer only.</p>
            @if ($protection === 'windows-account')
                <p class="flex items-center gap-2 text-sm text-green-800"><i class="ti ti-shield-check" aria-hidden="true"></i> The key is protected with this Windows account: the data cannot be read on another computer or account.</p>
            @elseif ($protection === 'none')
                <p class="flex items-center gap-2 text-sm text-amber-800"><i class="ti ti-shield-half" aria-hidden="true"></i> The key is stored in a file next to the data (not running as the desktop app). It is protected with the Windows account the next time the app opens.</p>
            @else
                <p class="text-sm text-gray-700">The key is made the first time personal data is saved.</p>
            @endif
            @if ($backups->isNotEmpty())
                <div>
                    <p class="label">Backups made before changes to the data (encrypted)</p>
                    <ul class="text-sm text-gray-700">
                        @foreach ($backups as $b)
                            <li>{{ $b['name'] }} · {{ \App\Support\Format::when(\Illuminate\Support\Carbon::createFromTimestamp($b['at'])) }}</li>
                        @endforeach
                    </ul>
                    <p class="help">How to restore one: see "Recovery" in docs/local-data-protection.md.</p>
                </div>
            @endif
        </section>

        <section class="card card-body flex flex-col gap-3 xl:col-span-2" aria-labelledby="closed-title" data-closed-events>
            <h2 id="closed-title" class="section-title">Closed events</h2>
            <p class="text-sm text-gray-700">Once an event is closed and everything for it has been sent, its personal data leaves this computer by itself (the server keeps everything). An event with records still waiting stays until they are sent or removed.</p>
            @if ($closedWithData->isEmpty())
                <p class="text-sm text-gray-600">No closed event's data is on this computer.</p>
            @else
                <ul class="divide-y divide-gray-100">
                    @foreach ($closedWithData as $row)
                        <li class="py-2 flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium text-gray-900">{{ $row['event']->name }}</span>
                            <span class="text-sm {{ $row['unsent'] ? 'text-amber-800' : 'text-gray-600' }}">{{ $row['unsent'] ? \App\Support\Plural::of($row['unsent'], 'record', 'records').' still waiting: kept' : 'Everything sent: can be removed' }}</span>
                        </li>
                    @endforeach
                </ul>
                <form method="POST" action="{{ route('data-security.prune') }}" data-confirm-title="Remove closed events' data from this computer?" data-confirm="Only events with nothing left to send. The server keeps everything." data-confirm-label="Remove">
                    @csrf
                    <button type="submit" class="btn btn-secondary"><i class="ti ti-trash" aria-hidden="true"></i> Remove now</button>
                </form>
            @endif
        </section>
    </div>
@endsection
