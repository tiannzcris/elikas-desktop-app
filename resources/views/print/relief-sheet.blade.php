@extends('print.layout')

@section('body')
    <p class="muted">Families at this center as of {{ $asOf ? \App\Support\Format::when($asOf) : 'never fetched' }}, plus families added on this computer. Fill in the boxes given, the date, and have the head of the family sign.</p>
    <table>
        <thead>
            <tr><th style="width: 5%">No.</th><th style="width: 30%">Family (head)</th><th class="num" style="width: 9%">Persons</th><th style="width: 12%">Purok</th>
                <th class="num" style="width: 9%">Boxes</th><th style="width: 12%">Date</th><th style="width: 23%">Signature</th></tr>
        </thead>
        <tbody>
            @forelse ($familiesHere as $i => $f)
                <tr class="sign"><td>{{ $i + 1 }}</td><td>{{ $f['label'] }}@unless ($f['remote_id']) <span class="muted">(not yet synced)</span>@endunless</td>
                    <td class="num">{{ $f['here'] ?? '' }}</td><td>{{ $f['purok'] }}</td><td></td><td></td><td></td></tr>
            @empty
                <tr><td colspan="7" class="muted">No family at this center on this computer.</td></tr>
            @endforelse
            {{-- Blank lines for families who arrive after printing. --}}
            @for ($i = 0; $i < 5; $i++)
                <tr class="sign"><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            @endfor
        </tbody>
    </table>
    <table style="margin-top: 18pt; border: 0;">
        <tr>
            <td style="border: 0; width: 50%; padding-top: 24pt;">______________________________<br>Distributed by (name and signature)</td>
            <td style="border: 0; width: 50%; padding-top: 24pt;">______________________________<br>Noted by (barangay official)</td>
        </tr>
    </table>
@endsection
