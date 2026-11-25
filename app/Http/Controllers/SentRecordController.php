<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiException;
use App\Models\EcBoardEntry;
use App\Models\LocalAuth;
use App\Models\Purok;
use App\Services\CentralApiService;
use Illuminate\Http\Request;

/**
 * Correcting a registration this device already sent (Sync > Sent). The
 * server is the source of truth once a record is in, so the form is filled
 * from the server and saved straight to it, online only, through the same
 * endpoints the web uses:
 *  - the person: PATCH /evacuees/{id} (sex and the sectoral flags);
 *  - the family: PATCH /families/{id}/household (who the head is, single-
 *    headed, the head's sex and age when the head is someone else, purok).
 * The server has no correction for an age group set at the EC Board or for
 * a family's name: those are shown, not edited (docs/backend: to ask for).
 */
class SentRecordController extends Controller
{
    public const OFFLINE = 'Connect to the internet to correct a record that was already sent.';

    public function __construct(private CentralApiService $api) {}

    public function edit(EcBoardEntry $entry)
    {
        $auth = LocalAuth::current();
        if ($redirect = $this->refuse($auth, $entry)) {
            return $redirect;
        }

        try {
            [$family, $person] = $this->fromServer($auth, $entry);
        } catch (CentralApiException $e) {
            return $this->back($e->isOffline() ? self::OFFLINE : $e->getMessage());
        }

        $ownBarangay = (int) ($family['barangay']['id'] ?? 0) === (int) $auth->barangay_id;

        return view('sync.sent-edit', [
            'currentUser' => $auth,
            'entry' => $entry,
            'family' => $family,
            'person' => $person,
            'headId' => $family['head_of_family']['id'] ?? null,
            'puroks' => $ownBarangay ? Purok::where('barangay_remote_id', $auth->barangay_id)->orderBy('name')->get() : collect(),
            'ageBrackets' => EcBoardEntry::AGE_BRACKETS,
        ]);
    }

    public function update(Request $request, EcBoardEntry $entry)
    {
        $auth = LocalAuth::current();
        if ($redirect = $this->refuse($auth, $entry)) {
            return $redirect;
        }

        $validated = $request->validate([
            'sex' => ['required', 'in:male,female'],
            'head_is_self' => ['nullable', 'boolean'],
            'is_single_headed' => ['nullable', 'in:0,1'],
            'head_sex' => ['nullable', 'in:male,female'],
            'head_is_minor' => ['nullable', 'in:0,1'],
            'purok_id' => ['nullable', 'integer'],
            // What the server had when the form opened: the family is only
            // sent when one of its answers changed.
            'original' => ['array'],
        ] + collect(EcBoardEntry::SECTORAL_FLAGS)->keys()->mapWithKeys(fn ($f) => [$f => ['nullable', 'boolean']])->all());

        // The person: sex and every flag (true or false: the server keeps both).
        $person = ['sex' => $validated['sex']];
        foreach (array_keys(EcBoardEntry::SECTORAL_FLAGS) as $flag) {
            $person[$flag] = $request->boolean($flag)
                && ! ($validated['sex'] === 'male' && in_array($flag, EcBoardEntry::FEMALE_ONLY_FLAGS, true));
        }

        try {
            $this->api->send('patch', "evacuees/{$entry->remote_id}", $auth->api_token, $person);
        } catch (CentralApiException $e) {
            return $this->refused($e);
        }

        // The person is saved on the server: the copy listed under Sent follows.
        $entry->update(['sex' => $person['sex']] + collect($person)->except('sex')->all());

        $household = $this->householdChanges($request, $entry, $validated);
        if ($household !== null) {
            try {
                $this->api->send('patch', "families/{$this->familyId($entry)}/household", $auth->api_token, $household);
            } catch (CentralApiException $e) {
                return $this->refused($e, 'The person was corrected, but not the family. ');
            }
        }

        return redirect()->to(route('sync.index').'#sent')->with('status', 'Corrected on the server.');
    }

    /**
     * The household answers to send (PATCH /families/{id}/household), or
     * null when nothing about the family changed.
     */
    private function householdChanges(Request $request, EcBoardEntry $entry, array $validated): ?array
    {
        $original = $validated['original'] ?? [];
        $currentHead = isset($original['head_id']) && $original['head_id'] !== '' ? (int) $original['head_id'] : null;
        $isSelf = (int) $entry->remote_id;

        // This person as the head, or the head on file kept (never left without
        // one: the server refuses that, and its answer is shown).
        $head = $request->boolean('head_is_self') ? $isSelf : ($currentHead === $isSelf ? null : $currentHead);
        $triState = fn ($v) => $v === null || $v === '' ? null : (bool) (int) $v;

        $payload = [
            'head_of_family_evacuee_id' => $head,
            'is_single_headed' => $triState($validated['is_single_headed'] ?? null),
            'head_is_minor' => $head ? null : $triState($validated['head_is_minor'] ?? null),
            'head_sex' => $head ? null : ($validated['head_sex'] ?? null),
        ];
        if ($request->has('purok_id')) {
            $payload['purok_id'] = isset($validated['purok_id']) ? (int) $validated['purok_id'] : null;
        }

        $before = [
            'head_of_family_evacuee_id' => $currentHead,
            'is_single_headed' => $triState($original['is_single_headed'] ?? null),
            'head_is_minor' => $currentHead ? null : $triState($original['head_is_minor'] ?? null),
            'head_sex' => $currentHead ? null : (($original['head_sex'] ?? '') ?: null),
        ];
        if ($request->has('purok_id')) {
            $before['purok_id'] = isset($original['purok_id']) && $original['purok_id'] !== '' ? (int) $original['purok_id'] : null;
        }

        return $payload == $before ? null : $payload;
    }

    /** The family (GET /families/{id}, with its members) and this person in it. */
    private function fromServer(LocalAuth $auth, EcBoardEntry $entry): array
    {
        $family = $this->api->send('get', "families/{$this->familyId($entry)}", $auth->api_token, [], 10)['data'] ?? [];
        $person = collect($family['members'] ?? [])->firstWhere('id', (int) $entry->remote_id);
        if (! $person) {
            throw new CentralApiException('This person is no longer in that family on the server (moved or removed). Look them up on the web.', CentralApiException::NOT_FOUND, 404);
        }

        return [$family, $person];
    }

    /** The family the server put the person in (kept at sync; older records: from the household). */
    private function familyId(EcBoardEntry $entry): ?int
    {
        $id = $entry->family_remote_id ?? $entry->household?->remote_id ?? $entry->existing_household_remote_id;

        return $id ? (int) $id : null;
    }

    /** Why this record can't be corrected here, as a redirect (null: it can). */
    private function refuse(?LocalAuth $auth, EcBoardEntry $entry)
    {
        if (! $auth) {
            return redirect()->route('login');
        }
        // Not sent yet: corrected on this device, as before.
        if (! $entry->isSynced()) {
            return redirect()->route('ec-board-entries.edit', $entry);
        }
        if (! $auth->can('evacuees.manage')) {
            return $this->back('Your account may not correct registrations. Ask your barangay administrator.');
        }
        if (! $entry->remote_id || ! $this->familyId($entry)) {
            return $this->back('This record was sent without the server\'s numbers. Correct it on the web, under Evacuees.');
        }

        return null;
    }

    /** The server's refusal, field by field when it gave them (422). */
    private function refused(CentralApiException $e, string $prefix = '')
    {
        if ($e->isOffline()) {
            return back()->withInput()->withErrors(['sent' => $prefix.self::OFFLINE]);
        }
        if ($e->kind === CentralApiException::UNAUTHENTICATED) {
            return back()->withInput()->withErrors(['sent' => $prefix.'Your session has expired. Log in again, then correct the record.']);
        }
        $messages = collect($e->errors())->flatten()->filter(fn ($m) => is_string($m))->values();

        return back()->withInput()->withErrors(['sent' => $prefix.($messages->isNotEmpty() ? $messages->implode(' ') : $e->getMessage())]);
    }

    private function back(string $message)
    {
        return redirect()->to(route('sync.index').'#sent')->withErrors(['sent' => $message]);
    }
}
