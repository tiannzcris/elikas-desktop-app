<?php

namespace App\Http\Controllers;

use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingPrivateHouseFamily;
use App\Models\Purok;
use App\Services\PrivateHouses;
use App\Support\EventLock;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Private houses: families of the official's barangay staying with
 * relatives or friends instead of an evacuation center. Added offline
 * (the server's quick Add family), sent by Sync with client_ref.
 */
class PrivateHouseController extends Controller
{
    public function index(PrivateHouses $houses)
    {
        $auth = LocalAuth::current();
        $event = EvacuationEvent::working();

        return view('private-houses.index', [
            'currentUser' => $auth,
            'event' => $event,
            'summary' => $houses->forEvent($event, (int) $auth->barangay_id),
        ]);
    }

    public function create()
    {
        $auth = LocalAuth::current();

        return view('private-houses.create', [
            'currentUser' => $auth,
            'event' => EvacuationEvent::working(),
            'puroks' => Purok::where('barangay_remote_id', $auth->barangay_id)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $auth = LocalAuth::current();
        $event = EvacuationEvent::working();
        EventLock::guard($event, EventLock::PRIVATE_HOUSES);

        // The server's rules (PrivateHouseController::store on the backend).
        $person = fn (string $prefix) => [
            "{$prefix}.first_name" => ['required', 'string', 'max:100'],
            "{$prefix}.last_name" => ['required', 'string', 'max:100'],
            "{$prefix}.sex" => ['required', Rule::in(['male', 'female'])],
            "{$prefix}.age" => ['required', 'integer', 'min:0', 'max:120'],
        ];
        // Blank member rows (added but left empty) are dropped first.
        $members = collect($request->input('members', []))
            ->reject(fn ($m) => collect($m)->filter(fn ($v) => trim((string) $v) !== '')->isEmpty())->values()->all();
        $request->merge(['members' => $members]);

        $validated = $request->validate(array_merge($person('head'), $person('members.*'), [
            'members' => ['array', 'max:30'],
            'host_name' => ['required', 'string', 'max:150'],
            'host_address' => ['nullable', 'required_without:host_purok_id', 'string', 'max:255'],
            'host_purok_id' => ['nullable', 'integer', Rule::exists('puroks', 'id')->where('barangay_remote_id', $auth->barangay_id)],
            'is_4ps_beneficiary' => ['nullable', 'boolean'],
        ]), [
            'host_name.required' => 'Enter the name of the relative or friend the family stays with.',
            'host_address.required_without' => 'Enter the address or choose the purok of the house.',
        ], [
            'head.first_name' => "head's first name", 'head.last_name' => "head's last name", 'head.sex' => "head's sex", 'head.age' => "head's age",
            'members.*.first_name' => 'first name', 'members.*.last_name' => 'last name', 'members.*.sex' => 'sex', 'members.*.age' => 'age',
        ]);

        $clean = fn (array $p) => ['first_name' => trim($p['first_name']), 'last_name' => trim($p['last_name']), 'sex' => $p['sex'], 'age' => (int) $p['age']];
        $family = PendingPrivateHouseFamily::create([
            'evacuation_event_id' => $event->id,
            'barangay_remote_id' => (int) $auth->barangay_id,
            'head' => $clean($validated['head']),
            'members' => array_map($clean, $validated['members'] ?? []),
            'host_name' => trim($validated['host_name']),
            'host_address' => isset($validated['host_address']) ? trim($validated['host_address']) : null,
            'host_purok_remote_id' => ! empty($validated['host_purok_id']) ? Purok::find($validated['host_purok_id'])->remote_id : null,
            'is_4ps_beneficiary' => (bool) ($validated['is_4ps_beneficiary'] ?? false),
        ]);

        return redirect()->route('private-houses.index')
            ->with('status', $family->label().' added to private houses on this device. It will be sent with the next sync.');
    }
}
