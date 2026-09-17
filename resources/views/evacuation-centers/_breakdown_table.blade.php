{{-- Renders one breakdown matrix (see EvacuationCenterController::breakdownMatrix())
     -- used twice on the same page for two genuinely different sources
     (last-known-from-server vs pending-on-this-device). Deliberately never
     combined into one table: reconciling them is only meaningful once
     everything has reached the central server. --}}
<table class="w-full text-xs">
    <thead>
        <tr class="text-gray-400 text-left">
            <th class="pb-2 font-medium">Age bracket</th>
            <th class="pb-2 font-medium text-right">Male</th>
            <th class="pb-2 font-medium text-right">Female</th>
            <th class="pb-2 font-medium text-right">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($ageBrackets as $key => $label)
            <tr class="border-t border-gray-100">
                <td class="py-1.5 text-gray-600">{{ $label }}</td>
                <td class="py-1.5 text-right text-gray-800">{{ $matrix[$key]['male'] }}</td>
                <td class="py-1.5 text-right text-gray-800">{{ $matrix[$key]['female'] }}</td>
                <td class="py-1.5 text-right font-semibold text-gray-800">{{ $matrix[$key]['total'] }}</td>
            </tr>
        @endforeach
        <tr class="border-t border-gray-200">
            <td class="py-1.5 font-bold text-gray-700">Total</td>
            <td class="py-1.5 text-right font-bold text-gray-800">{{ $matrix['total']['male'] }}</td>
            <td class="py-1.5 text-right font-bold text-gray-800">{{ $matrix['total']['female'] }}</td>
            <td class="py-1.5 text-right font-bold text-gray-800">{{ $matrix['total']['total'] }}</td>
        </tr>
    </tbody>
</table>
