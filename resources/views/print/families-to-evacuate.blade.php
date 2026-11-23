@extends('print.layout')

@section('body')
    <p class="muted">{{ \App\Support\Plural::of($list['summary']['families'], 'family', 'families') }} listed ({{ \App\Support\Plural::of($list['summary']['persons'], 'person', 'persons') }}), {{ number_format($list['summary']['checked_in']) }} checked in. List as of {{ $list['fetched_at'] ? \App\Support\Format::when($list['fetched_at']) : 'never fetched' }}. A listed family is not an evacuee until it is checked in. High-risk puroks first.</p>
    @forelse ($list['groups'] as $group)
        <h2>{{ $group['name'] }}@if ($group['high_risk']) · HIGH RISK{{ $group['risk'] ? ': '.$group['risk'] : '' }}@endif
            <span class="muted" style="font-weight: 400;">· {{ \App\Support\Plural::of($group['families']->count(), 'family', 'families') }}, {{ \App\Support\Plural::of($group['persons'], 'person', 'persons') }}</span></h2>
        <table>
            <thead><tr><th style="width: 5%">No.</th><th>Head of the family</th><th class="num" style="width: 10%">Members</th><th style="width: 16%">Contact</th><th style="width: 14%">Status</th><th style="width: 14%">Checked in at</th></tr></thead>
            <tbody>
                @foreach ($group['families'] as $i => $f)
                    <tr><td>{{ $i + 1 }}</td><td>{{ $f['head'] ?? '' }}{{ ! empty($f['note']) ? ' · '.$f['note'] : '' }}</td><td class="num">{{ $f['members_count'] }}</td>
                        <td>{{ $f['contact_number'] ?? '' }}</td><td>{{ $f['is_checked_in'] ? 'Checked in' : 'Expected' }}</td><td></td></tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p>No families listed.</p>
    @endforelse
@endsection
