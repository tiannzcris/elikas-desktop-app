<?php

namespace App\Services;

use App\Models\Barangay;
use App\Models\DownloadLog;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Evacuee;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Models\PendingCheckOut;
use App\Models\PendingHandout;
use App\Models\PendingListCheckIn;
use App\Models\PendingPrivateHouseFamily;
use App\Models\PendingReEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moving waiting records to another computer (a USB drive when the
 * internet is down here): Export writes every record not yet sent to one
 * file encrypted with a passphrase the user chooses; Import on another
 * computer, signed in as the same account, puts them in its queue.
 *
 * Each record keeps its client_ref, so whichever computer sends it first,
 * the server keeps it once and answers the other with the first answer.
 * (A check-out has no client_ref; sending one twice is answered "no one in
 * this family is still checked in", which Sync takes as done.)
 *
 * Computers have different keys, so the file is encrypted with the
 * passphrase (Argon2id, then XSalsa20-Poly1305: libsodium), not a
 * computer's key. Records point at events, centers and barangays by their
 * server ids, which every computer shares.
 */
class WaitingRecordsTransfer
{
    public const FORMAT = 'elikas-waiting-records';

    public const VERSION = 1;

    public const MIN_PASSPHRASE = 8;

    public function __construct(private SyncService $sync) {}

    // ------------------------------------------------------------------ export

    /** @return array{bytes: string, count: int, file_name: string} */
    public function export(LocalAuth $auth, string $passphrase): array
    {
        $payload = $this->collect($auth);

        return [
            'bytes' => $this->seal(json_encode($payload, JSON_THROW_ON_ERROR), $passphrase),
            'count' => count($payload['records']),
            'file_name' => 'E-LIKAS-waiting-records_'.Str::slug((string) ($auth->barangay_name ?: $auth->name)).'_'.now(\App\Support\Format::TIMEZONE)->format('Y-m-d-Hi').'.elikas',
        ];
    }

    /** Every record not yet sent, in the order Sync sends them, with server ids instead of this computer's. */
    public function collect(LocalAuth $auth): array
    {
        $events = EvacuationEvent::pluck('remote_id', 'id');
        $centers = EvacuationCenter::pluck('remote_id', 'id');
        $barangays = Barangay::pluck('remote_id', 'id');

        // Households records point at (this computer's own, sent or not), by a client_ref.
        $households = [];
        $household = function (?int $localId) use (&$households, $events, $centers, $barangays) {
            if (! $localId || ! ($family = Family::with('evacuees')->find($localId))) {
                return null;
            }
            if (! $family->client_ref) {
                $family->update(['client_ref' => (string) Str::uuid()]);
            }
            $households[$family->client_ref] ??= $this->familyRow($family, $events, $centers, $barangays);

            return $family->client_ref;
        };

        $records = [];
        foreach ($this->sync->unsent() as $item) {
            $m = $item['model'];
            $base = ['kind' => $item['kind'], 'client_ref' => $m->client_ref, 'created_at' => $m->created_at?->toIso8601String(),
                'event_remote_id' => $events[$m->evacuation_event_id] ?? null];

            $records[] = $base + match ($item['kind']) {
                'entry' => ['center_remote_id' => $centers[$m->evacuation_center_id] ?? null,
                    'household_client_ref' => $household($m->household_family_local_id),
                    'attributes' => collect($m->getAttributes())->only(['sex', 'age_bracket', 'head_is_self', 'is_pwd', 'is_pregnant', 'is_lactating',
                        'is_solo_parent', 'is_indigenous_person', 'is_4ps_beneficiary', 'existing_household_remote_id', 'originated_household'])->all()
                        + ['new_household_head_name' => $m->new_household_head_name]],
                'family' => ['household_client_ref' => $household($m->id)],
                'returning' => ['center_remote_id' => $centers[$m->evacuation_center_id] ?? null,
                    'attributes' => ['source_family_remote_id' => $m->source_family_remote_id, 'family_name' => $m->family_name, 'source_event_name' => $m->source_event_name,
                        'members' => $m->members, 'duplicate_decision' => $m->duplicate_decision, 'link_family_remote_id' => $m->link_family_remote_id]],
                'private' => ['attributes' => ['barangay_remote_id' => $m->barangay_remote_id, 'head' => $m->head, 'members' => $m->members, 'host_name' => $m->host_name,
                    'host_address' => $m->host_address, 'host_purok_remote_id' => $m->host_purok_remote_id, 'is_4ps_beneficiary' => $m->is_4ps_beneficiary]],
                'checkout' => ['center_remote_id' => $centers[$m->evacuation_center_id] ?? null,
                    'household_client_ref' => $m->family_remote_id ? null : $household($m->household_family_local_id),
                    'attributes' => ['family_remote_id' => $m->family_remote_id, 'family_label' => $m->family_label, 'status' => $m->status]],
                'download' => ['attributes' => collect($m->getAttributes())->only(['document', 'format', 'file_name', 'evacuation_event_remote_id', 'evacuation_center_remote_id',
                    'user_remote_id', 'user_name', 'saved_to', 'generated_offline', 'file_type', 'action', 'report_remote_id'])->all()],
                'handout' => ['attributes' => ['round_remote_id' => $m->round_remote_id, 'round_number' => $m->round_number, 'family_remote_id' => $m->family_remote_id,
                    'family_label' => $m->family_label, 'higher_priority_waiting' => $m->higher_priority_waiting]],
                'checkin' => ['center_remote_id' => $m->evacuation_center_id ? ($centers[$m->evacuation_center_id] ?? null) : null,
                    'attributes' => ['listed_family_remote_id' => $m->listed_family_remote_id, 'family_label' => $m->family_label, 'members_count' => $m->members_count,
                        'host_name' => $m->host_name, 'host_purok_remote_id' => $m->host_purok_remote_id, 'host_address' => $m->host_address]],
            };
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'app_version' => (string) config('nativephp.version'),
            'exported_at' => now()->toIso8601String(),
            'account' => ['id' => (int) $auth->remote_user_id, 'name' => $auth->name, 'email' => $auth->email],
            'households' => array_values($households),
            'records' => $records,
        ];
    }

    private function familyRow(Family $family, $events, $centers, $barangays): array
    {
        return [
            'client_ref' => $family->client_ref,
            'event_remote_id' => $events[$family->evacuation_event_id] ?? null,
            'center_remote_id' => $family->evacuation_center_id ? ($centers[$family->evacuation_center_id] ?? null) : null,
            'barangay_remote_id' => $barangays[$family->barangay_id] ?? null,
            'head_entry_client_ref' => $family->head_ec_board_entry_id ? EcBoardEntry::whereKey($family->head_ec_board_entry_id)->value('client_ref') : null,
            'attributes' => [
                'purok_remote_id' => $family->purok_remote_id, 'displacement_type' => $family->displacement_type, 'is_4ps_beneficiary' => $family->is_4ps_beneficiary,
                'home_address' => $family->home_address, 'name' => $family->name, 'is_single_headed' => $family->is_single_headed, 'head_is_minor' => $family->head_is_minor,
                'head_sex' => $family->head_sex, 'created_via_ec_board' => $family->created_via_ec_board, 'remote_id' => $family->remote_id,
                'synced_at' => $family->synced_at?->toIso8601String(),
            ],
            'evacuees' => $family->evacuees->map(fn (Evacuee $e) => collect($e->getAttributes())->except(['id', 'family_id', 'created_at', 'updated_at', 'first_name', 'middle_name',
                'last_name', 'suffix', 'contact_number', 'date_of_birth'])->all() + ['first_name' => $e->first_name, 'middle_name' => $e->middle_name, 'last_name' => $e->last_name,
                'suffix' => $e->suffix, 'contact_number' => $e->contact_number, 'date_of_birth' => $e->date_of_birth?->toDateString()])->all(),
        ];
    }

    // ------------------------------------------------------------------ import

    /**
     * Puts the file's records in this computer's queue. Refused for another
     * account, a wrong passphrase, or events/centers this computer does not
     * know yet. Records already here (same client_ref) are skipped.
     *
     * @return array{added: int, skipped: int}
     */
    public function import(LocalAuth $auth, string $fileBytes, string $passphrase): array
    {
        $payload = json_decode($this->open($fileBytes, $passphrase), true);
        if (($payload['format'] ?? null) !== self::FORMAT || ($payload['version'] ?? 0) > self::VERSION) {
            throw new WaitingRecordsException('This is not a file of waiting records from E-LIKAS, or it was made by a newer version.');
        }
        if ((int) ($payload['account']['id'] ?? 0) !== (int) $auth->remote_user_id) {
            throw new WaitingRecordsException('These records were made by '.($payload['account']['name'] ?? 'another account').' ('.($payload['account']['email'] ?? '').'). Sign in as that account on this computer to import them.');
        }

        $events = EvacuationEvent::pluck('id', 'remote_id');
        $centers = EvacuationCenter::pluck('id', 'remote_id');
        $barangays = Barangay::pluck('id', 'remote_id');
        $missing = [];
        $need = function ($map, $remoteId, string $what) use (&$missing) {
            if ($remoteId === null) {
                return null;
            }
            if (! isset($map[$remoteId])) {
                $missing[] = "{$what} #{$remoteId}";

                return null;
            }

            return $map[$remoteId];
        };
        foreach (array_merge($payload['households'], $payload['records']) as $row) {
            $need($events, $row['event_remote_id'] ?? null, 'event');
            $need($centers, $row['center_remote_id'] ?? null, 'center');
            $need($barangays, $row['barangay_remote_id'] ?? null, 'barangay');
        }
        if ($missing) {
            throw new WaitingRecordsException('This computer does not have every event and center these records need yet ('.implode(', ', array_unique($missing)).'). Refresh reference data while online, then import again.');
        }

        $added = 0;
        $skipped = 0;
        DB::transaction(function () use ($payload, $events, $centers, $barangays, &$added, &$skipped) {
            // Households first: records point at them.
            $familyIds = [];
            $created = [];
            $heads = [];
            foreach ($payload['households'] as $h) {
                if ($existing = Family::where('client_ref', $h['client_ref'])->value('id')) {
                    $familyIds[$h['client_ref']] = $existing;

                    continue;
                }
                $family = Family::create($h['attributes'] + [
                    'client_ref' => $h['client_ref'],
                    'evacuation_event_id' => $events[$h['event_remote_id']],
                    'evacuation_center_id' => $h['center_remote_id'] ? $centers[$h['center_remote_id']] : null,
                    'barangay_id' => $barangays[$h['barangay_remote_id']],
                ]);
                foreach ($h['evacuees'] as $e) {
                    Evacuee::create($e + ['family_id' => $family->id]);
                }
                $familyIds[$h['client_ref']] = $family->id;
                $created[$h['client_ref']] = true;
                $heads[$family->id] = $h['head_entry_client_ref'];
            }

            foreach ($payload['records'] as $r) {
                $model = match ($r['kind']) {
                    'entry' => EcBoardEntry::class, 'family' => Family::class, 'returning' => PendingReEnrollment::class,
                    'private' => PendingPrivateHouseFamily::class, 'checkout' => PendingCheckOut::class, 'checkin' => PendingListCheckIn::class, 'handout' => PendingHandout::class, 'download' => DownloadLog::class,
                    default => null,
                };
                if ($model === null) {
                    continue;
                }
                // Already on this computer (imported before, or made here).
                if ($r['kind'] === 'family' || ($r['client_ref'] && $model::where('client_ref', $r['client_ref'])->exists())) {
                    if ($r['kind'] === 'family' && isset($created[$r['household_client_ref']])) {
                        $added++;
                    } else {
                        $skipped++;
                    }

                    continue;
                }
                $attributes = ($r['attributes'] ?? []) + [
                    'client_ref' => $r['client_ref'],
                    'evacuation_event_id' => $r['event_remote_id'] !== null ? $events[$r['event_remote_id']] : null,
                ];
                if (array_key_exists('center_remote_id', $r)) {
                    $attributes['evacuation_center_id'] = $r['center_remote_id'] ? $centers[$r['center_remote_id']] : null;
                }
                if (! empty($r['household_client_ref'])) {
                    $attributes['household_family_local_id'] = $familyIds[$r['household_client_ref']] ?? null;
                }
                $record = new $model;
                $record->forceFill(array_intersect_key($attributes, array_flip(array_merge($record->getFillable(), ['client_ref']))));
                $record->created_at = $r['created_at'] ?? now();
                $record->save();
                $added++;
            }

            // A household's head is one of its entries.
            foreach ($heads as $familyId => $entryRef) {
                if ($entryRef && ($entryId = EcBoardEntry::where('client_ref', $entryRef)->value('id'))) {
                    Family::whereKey($familyId)->update(['head_ec_board_entry_id' => $entryId]);
                }
            }
        });

        return ['added' => $added, 'skipped' => $skipped];
    }

    // ------------------------------------------------------------ encryption

    public function seal(string $plain, string $passphrase): string
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->derive($passphrase, $salt);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
        sodium_memzero($key);

        return json_encode([
            'format' => self::FORMAT, 'version' => self::VERSION,
            'kdf' => 'argon2id13', 'cipher' => 'xsalsa20poly1305',
            'salt' => base64_encode($salt), 'nonce' => base64_encode($nonce), 'data' => base64_encode($cipher),
        ]);
    }

    public function open(string $fileBytes, string $passphrase): string
    {
        $file = json_decode($fileBytes, true);
        if (($file['format'] ?? null) !== self::FORMAT || empty($file['data'])) {
            throw new WaitingRecordsException('This is not a file of waiting records from E-LIKAS.');
        }
        $key = $this->derive($passphrase, base64_decode($file['salt']));
        $plain = sodium_crypto_secretbox_open(base64_decode($file['data']), base64_decode($file['nonce']), $key);
        sodium_memzero($key);
        if ($plain === false) {
            throw new WaitingRecordsException('The passphrase is wrong, or the file is damaged.');
        }

        return $plain;
    }

    private function derive(string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }
}
