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
     * exactly (in the same order) for the 7 real brackets.
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

        return $response->json('data.age_groups') ?? [];
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
     * HOUSEHOLD's family id, not this evacuee's own id -- confirmed the
     * real response separately carries a top-level data.evacuee_id for
     * the actual evacuee just created, which is what this method returns
     * and what gets stored as this entry's remote_id. Storing the family
     * id here instead would be wrong: it wouldn't identify this specific
     * evacuee at all, and for an "existing household" entry it wouldn't
     * even be unique to this sync (every entry added to the same
     * household would get the same family id back).
     */
    public function addEvacuee(string $token, int $centerRemoteId, array $payload): int
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

        return (int) $response->json('data.evacuee_id');
    }
}
