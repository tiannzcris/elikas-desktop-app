<?php

namespace App\Services;

use App\Exceptions\CentralApiAuthenticationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class CentralApiService
{
    private function baseUrl(): string
    {
        return config('elikas.central_api_url');
    }

    /**
     * Logs in against the CENTRAL server's existing /auth/login endpoint --
     * the exact same one the web dashboard uses, so there's only ever one
     * auth system in the whole project. Throws a clear, SPECIFIC message
     * distinguishing "couldn't reach the server at all" (no internet --
     * an expected, normal state for this app, not a failure) from "reached
     * the server, but the credentials were wrong."
     */
    public function login(string $email, string $password): array
    {
        try {
            $response = Http::timeout(10)->post("{$this->baseUrl()}/auth/login", [
                'email' => $email,
                'password' => $password,
            ]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException(
                'Could not reach the central server. Check your internet connection and try again -- '.
                'if you\'ve logged in on this device before, you can close this screen and keep working offline instead.'
            );
        }

        if (! $response->successful()) {
            throw new \RuntimeException($response->json('message') ?? 'Login failed.');
        }

        return $response->json('data');
    }

    /**
     * Pulls current barangays/events/centers from the central server, for
     * caching locally -- called right after login, and whenever the user
     * manually asks to refresh while online.
     */
    public function fetchReferenceData(string $token): array
    {
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        try {
            $barangays = Http::withHeaders($headers)->timeout(10)->get("{$this->baseUrl()}/barangays");
            $events = Http::withHeaders($headers)->timeout(10)->get("{$this->baseUrl()}/evacuation-events");
            $centers = Http::withHeaders($headers)->timeout(10)->get("{$this->baseUrl()}/evacuation-centers");
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Could not reach the central server to refresh reference data. Check your internet connection.');
        }

        foreach ([$barangays, $events, $centers] as $response) {
            if (! $response->successful()) {
                throw new \RuntimeException('The central server rejected the request -- your login may have expired. Try logging in again while online.');
            }
        }

        return [
            'barangays' => $barangays->json('data'),
            'events' => $events->json('data'),
            'centers' => $centers->json('data'),
        ];
    }

    /**
     * Pulls the full evacuee/family roster from the central server, for the
     * "All Evacuees" page's local cache. Unlike fetchReferenceData() (only
     * called at login + manual refresh), this is called on every visit to
     * that page while online, since that page is meant to reflect what's
     * currently on the server, not just what was true at last login.
     *
     * GET /families is paginated (confirmed against the real production
     * API: {success, message, data: {data: [...], links, meta}} -- the
     * actual family records are at data.data, NOT data). Rather than
     * walking pages one at a time -- confirmed by direct testing to be
     * slow and unreliable here (17 sequential requests at the default
     * page size of 20 took 70+ seconds and ultimately failed with a
     * connection error) -- this asks for a single page large enough to
     * cover the whole roster in one request; the API honors per_page and
     * returned all 339 real records in ~4s that way. per_page is set well
     * above current volume with room to grow; if the roster ever exceeds
     * it, this naturally falls back to just the first page instead of
     * failing, so growth degrades gracefully rather than breaking.
     */
    public function fetchEvacuees(string $token): array
    {
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        try {
            $response = Http::withHeaders($headers)->timeout(20)->get("{$this->baseUrl()}/families", ['per_page' => 1000]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Could not reach the central server to refresh the evacuee list. Check your internet connection.');
        }

        if (! $response->successful()) {
            throw new \RuntimeException('The central server rejected the request -- your login may have expired. Try logging in again while online.');
        }

        return $response->json('data.data') ?? [];
    }

    /**
     * Pulls ONE evacuation center's own live age/sex breakdown for ONE
     * event, from the EC Board's real existing "quick-count" endpoint
     * (confirmed against elikas-backend's
     * EvacuationCenterController::quickCount() -- there is no bulk
     * "all centers" breakdown endpoint; an earlier version of this method
     * assumed one existed at /evacuation-centers/evacuee-breakdown, which
     * was never real).
     *
     * The real endpoint is scoped to exactly one center+event per call and
     * computes the breakdown LIVE on every request (not a stored
     * snapshot), so this is called on demand -- when a specific center's
     * detail page is opened while online (see
     * EvacuationCenterController::show()) -- rather than bundled into
     * fetchReferenceData(), which would need N requests (one per cached
     * center) to cover the same ground for centers nobody may even be
     * looking at.
     *
     * Response shape includes a fixed 7-bracket 'age_groups' array plus a
     * trailing 'unclassified' bracket for anyone missing sex/age_bracket
     * entirely -- see this project's EcBoardEntry::AGE_BRACKETS, which
     * mirrors the backend's own EvacuationCenterQuickCount::AGE_BRACKETS
     * exactly (in the same order) for the 7 real brackets. The SAME
     * response also always carries 'sectoral_groups' (all 8 groups,
     * zero-filled) and 'beneficiaries_4ps' -- confirmed against the
     * backend's EvacuationCenterQuickCountResource, there is no separate
     * endpoint for that data. Returns the full decoded 'data' object
     * rather than just age_groups (as an earlier version of this method
     * did) so callers needing the sectoral figures don't need a second
     * request for the same underlying row.
     */
    public function fetchCenterQuickCount(string $token, int $centerRemoteId, int $eventRemoteId): array
    {
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        try {
            $response = Http::withHeaders($headers)->timeout(10)->get(
                "{$this->baseUrl()}/evacuation-centers/{$centerRemoteId}/quick-count",
                ['evacuation_event_id' => $eventRemoteId]
            );
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Could not reach the central server to refresh this center\'s breakdown.');
        }

        if (! $response->successful()) {
            throw new \RuntimeException('The central server rejected the request -- your login may have expired. Try logging in again while online.');
        }

        return $response->json('data') ?? [];
    }

    /**
     * Pulls the households already registered at ONE center, for ONE
     * event, straight from the central server -- confirmed against
     * elikas-backend's EvacuationCenterController::familiesAtCenter()
     * (the exact same call the web dashboard's own "Add Evacuee ->
     * Existing household" picker makes). Powers the EC Board's household
     * dropdown with households this device may never have locally cached
     * (e.g. registered from a different device) -- called on demand when
     * the EC Board page is opened while online (see
     * EvacuationCenterController::refreshHouseholds()), same
     * one-center-at-a-time principle as fetchCenterQuickCount(), not a
     * bulk fetch.
     *
     * @return list<array{id: int, name: ?string, head_of_family: ?array, member_count: int}>
     */
    public function fetchFamiliesAtCenter(string $token, int $centerRemoteId, int $eventRemoteId): array
    {
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        try {
            $response = Http::withHeaders($headers)->timeout(10)->get(
                "{$this->baseUrl()}/evacuation-centers/{$centerRemoteId}/families",
                ['evacuation_event_id' => $eventRemoteId]
            );
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Could not reach the central server to refresh this center\'s households.');
        }

        if (! $response->successful()) {
            throw new \RuntimeException('The central server rejected the request -- your login may have expired. Try logging in again while online.');
        }

        return $response->json('data') ?? [];
    }

    /**
     * Pushes one locally-registered family to the CENTRAL server's existing
     * registration endpoint -- this IS the sync mechanism. No separate sync
     * protocol: it's the same authenticated request the web dashboard would
     * make, just issued from this device instead of a browser.
     */
    public function registerFamily(string $token, array $payload): int
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->timeout(15)->post("{$this->baseUrl()}/families/register", $payload);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Could not reach the central server.');
        }

        // 401 specifically means the token itself is no good -- distinct
        // from a 422 (this record's data is invalid) or any other status.
        // Every other queued family will fail the exact same way with the
        // same dead token, so this gets its own exception type rather than
        // being folded into the generic message below: the caller needs to
        // tell "your session is gone" apart from "this record has bad data"
        // to show the right message and stop retrying with a token that
        // will never start working again on its own.
        if ($response->status() === 401) {
            throw new CentralApiAuthenticationException($response->json('message') ?? 'Unauthenticated.');
        }

        if (! $response->successful()) {
            $message = $response->json('message') ?? 'The central server rejected this record.';
            $errors = $response->json('errors');
            if ($errors) {
                $message .= ' '.collect($errors)->flatten()->implode(' ');
            }
            throw new \RuntimeException($message);
        }

        return (int) $response->json('data.id');
    }

    /**
     * Pushes one locally-added EC Board evacuee entry to the CENTRAL
     * server's real "Add Evacuee" endpoint (confirmed against
     * elikas-backend's EvacuationCenterController::addEvacuee()) -- the EC
     * Board equivalent of registerFamily() above, same shape, same error
     * handling. {center} is this entry's center's REMOTE id, matching how
     * registerFamily's payload resolves every other foreign key to a
     * remote id before it's sent.
     *
     * The response wraps a FamilyResource, so top-level data.id is the
     * HOUSEHOLD's family id, and the response separately carries a
     * top-level data.evacuee_id for the actual evacuee just created.
     * Returns both: 'evacuee_id' is what every caller stores as this
     * entry's OWN remote_id (family id alone wouldn't identify this
     * specific evacuee, and for an "existing household" entry wouldn't
     * even be unique to this sync -- every entry added to the same
     * household gets the same family id back). 'family_id' exists
     * specifically for household_mode='new' calls, so
     * FamilyController::sync() can stamp the freshly-assigned remote id
     * onto this entry's local created_via_ec_board Family too -- see
     * EcBoardEntry::toSyncPayload()'s own originated_household docblock.
     *
     * @return array{evacuee_id: int, family_id: int}
     */
    public function addEvacuee(string $token, int $centerRemoteId, array $payload): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->timeout(15)->post("{$this->baseUrl()}/evacuation-centers/{$centerRemoteId}/evacuees", $payload);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Could not reach the central server.');
        }

        if ($response->status() === 401) {
            throw new CentralApiAuthenticationException($response->json('message') ?? 'Unauthenticated.');
        }

        if (! $response->successful()) {
            $message = $response->json('message') ?? 'The central server rejected this entry.';
            $errors = $response->json('errors');
            if ($errors) {
                $message .= ' '.collect($errors)->flatten()->implode(' ');
            }
            throw new \RuntimeException($message);
        }

        return [
            'evacuee_id' => (int) $response->json('data.evacuee_id'),
            'family_id' => (int) $response->json('data.id'),
        ];
    }

    /**
     * Calls the CENTRAL server's real "Quick Departure" endpoint directly
     * (confirmed against elikas-backend's EvacuationCenterController::
     * quickDeparture()) -- the reverse of addEvacuee() above: marks N
     * currently-evacuated people at this center as departed by age
     * bracket + sex + quantity, not by name. Unlike every other write in
     * this service, this has NO local/offline path at all -- it needs the
     * central server's own true current "who's here" set to correctly
     * pick who departs, which this device's own local cache can never
     * guarantee reflects (other devices may have added/synced evacuees
     * this one never pulled). Always called live, online-only, straight
     * from EvacuationCenterController::quickDeparture() -- see that
     * method's own docblock.
     *
     * Throws with the backend's own exact message on failure (e.g. the
     * "Only N matching evacuee(s) are currently here..." 422), same
     * generic error-unwrapping as every other method here, so the caller
     * can show that exact wording rather than a generic fallback.
     */
    public function quickDeparture(string $token, int $centerRemoteId, array $payload): void
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->timeout(15)->post("{$this->baseUrl()}/evacuation-centers/{$centerRemoteId}/quick-departure", $payload);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Could not reach the central server.');
        }

        if ($response->status() === 401) {
            throw new CentralApiAuthenticationException($response->json('message') ?? 'Unauthenticated.');
        }

        if (! $response->successful()) {
            $message = $response->json('message') ?? 'The central server rejected this request.';
            $errors = $response->json('errors');
            if ($errors) {
                $message .= ' '.collect($errors)->flatten()->implode(' ');
            }
            throw new \RuntimeException($message);
        }
    }

    /**
     * Pushes this device's locally-saved EC Board sectoral/4Ps figures to
     * the CENTRAL server's real quick-count save endpoint (confirmed
     * against elikas-backend's EvacuationCenterController::
     * updateQuickCount()). Unlike registerFamily()/addEvacuee() above,
     * this is a single upsert-by-(center,event) PUT, not a "create a new
     * record" POST -- matching how this figure is a "simple value update"
     * on this device too (see EvacuationCenterQuickCount's own docblock),
     * not a growing queue of individual entries. No id is returned or
     * needed: the server resolves the row to update from
     * evacuation_event_id (in the payload) plus {center} in the URL, the
     * same two keys this device's own local row is unique on.
     */
    public function updateQuickCount(string $token, int $centerRemoteId, array $payload): void
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->timeout(15)->put("{$this->baseUrl()}/evacuation-centers/{$centerRemoteId}/quick-count", $payload);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Could not reach the central server.');
        }

        if ($response->status() === 401) {
            throw new CentralApiAuthenticationException($response->json('message') ?? 'Unauthenticated.');
        }

        if (! $response->successful()) {
            $message = $response->json('message') ?? 'The central server rejected these figures.';
            $errors = $response->json('errors');
            if ($errors) {
                $message .= ' '.collect($errors)->flatten()->implode(' ');
            }
            throw new \RuntimeException($message);
        }
    }
}
