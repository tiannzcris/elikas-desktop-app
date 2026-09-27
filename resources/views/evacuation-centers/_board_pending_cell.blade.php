{{-- One "On this device" cell: the pending total, with its male/female
     split beneath it; a dash when nothing is pending for that row. --}}
@if ($male + $female > 0)
    <td class="ecb-pending" title="{{ $male }} male, {{ $female }} female -- not yet synced">
        <span class="ecb-pending-total">+{{ $male + $female }}</span>
        <span class="ecb-pending-split">{{ $male }}M · {{ $female }}F</span>
    </td>
@else
    <td class="ecb-pending ecb-pending-none">—</td>
@endif
