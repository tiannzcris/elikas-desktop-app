{{-- A purok's high-risk marks (PATCH /puroks/{id}, online). --}}
<form method="POST" action="{{ route('puroks.mark', $purok) }}" class="flex flex-wrap items-center gap-3 text-sm" data-purok-marks="{{ $purok->remote_id }}">
    @csrf
    <span class="text-gray-600">High risk:</span>
    <label class="flex items-center gap-1.5"><input type="checkbox" name="high_risk_flood" value="1" class="h-4 w-4" @checked($purok->high_risk_flood)> Flood</label>
    <label class="flex items-center gap-1.5"><input type="checkbox" name="high_risk_landslide" value="1" class="h-4 w-4" @checked($purok->high_risk_landslide)> Landslide</label>
    <button type="submit" class="btn btn-secondary btn-sm" data-online-only>Save marks</button>
</form>
