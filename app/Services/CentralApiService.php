<?php

namespace App\Services;

use App\Exceptions\CentralApiAuthenticationException;
use App\Exceptions\CentralApiException;
use App\Support\AccountState;
use App\Support\DeviceHeaders;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Every call to the central E-LIKAS server (the same /api/v1 the web uses).
 * All requests go through send(), which turns any failure into a
 * CentralApiException with a kind (offline, unauthenticated, forbidden,
 * not_found, conflict, invalid, gone, server) and the server's own words.
 */
class CentralApiService
{
    public const OFFLINE_MESSAGE = 'Could not reach the central server. Check the internet connection.';

    private function baseUrl(): string
    {
        return rtrim(config('elikas.central_api_url'), '/');
    }

    /**
     * One request. Returns the decoded JSON body (not only its data block,
     * since some endpoints put fields beside it).
     *
     * @throws CentralApiException
     */
    public function send(string $method, string $path, ?string $token = null, array $body = [], int $timeout = 15): array
    {
        $request = Http::acceptJson()->timeout($timeout)->withHeaders(DeviceHeaders::all());
        if ($token !== null) {
            $request = $request->withToken($token);
        }

        $url = $this->baseUrl().'/'.ltrim($path, '/');

        try {
            $response = strtolower($method) === 'get'
                ? $request->get($url, $body)
                : $request->send(strtoupper($method), $url, ['json' => $body]);
        } catch (ConnectionException $e) {
            throw new CentralApiException(self::OFFLINE_MESSAGE, CentralApiException::OFFLINE);
        }

        if ($response->successful()) {
            AccountState::serverAnswered();

            return $response->json() ?? [];
        }

        throw $this->failure($response);
    }

    /**
     * A multipart POST (a file with fields), with the same headers and
     * error handling as send().
     *
     * @param  array<string, array{contents: string, filename: string}>  $files
     */
    public function sendMultipart(string $path, string $token, array $fields, array $files = [], int $timeout = 30): array
    {
        $request = Http::acceptJson()->timeout($timeout)->withHeaders(DeviceHeaders::all())->withToken($token)->asMultipart();
        foreach ($files as $name => $file) {
            $request = $request->attach($name, $file['contents'], $file['filename']);
        }

        try {
            $response = $request->post($this->baseUrl().'/'.ltrim($path, '/'), $fields);
        } catch (ConnectionException $e) {
            throw new CentralApiException(self::OFFLINE_MESSAGE, CentralApiException::OFFLINE);
        }

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        throw $this->failure($response);
    }

    /**
     * A file from the server (GET /reports/{id}/download): its bytes, and
     * the file name the server gives (a practice event's starts with
     * DEMO-not-for-submission_; keep it as it is).
     *
     * @return array{bytes: string, filename: ?string, content_type: ?string}
     */
    public function download(string $path, string $token, int $timeout = 60): array
    {
        try {
            $response = Http::timeout($timeout)->withHeaders(DeviceHeaders::all())->withToken($token)
                ->accept('*/*')->get($this->baseUrl().'/'.ltrim($path, '/'));
        } catch (ConnectionException $e) {
            throw new CentralApiException(self::OFFLINE_MESSAGE, CentralApiException::OFFLINE);
        }
        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $filename = null;
        $disposition = (string) $response->header('Content-Disposition');
        if (preg_match("/filename\\*=UTF-8''([^;]+)/i", $disposition, $m)) {
            $filename = rawurldecode($m[1]);
        } elseif (preg_match('/filename="?([^";]+)"?/i', $disposition, $m)) {
            $filename = $m[1];
        }

        return ['bytes' => $response->body(), 'filename' => $filename ? basename($filename) : null, 'content_type' => $response->header('Content-Type') ?: null];
    }

    private function failure(Response $response): CentralApiException
    {
        $status = $response->status();

        if ($status === 401) {
            return new CentralApiAuthenticationException;
        }

        $code = $response->json('errors.code');
        // The account is on a temporary password: everything waits for a new one.
        if ($status === 403 && $code === 'password_change_required') {
            AccountState::passwordChangeRequired();
        }
        // The administrator switched maintenance mode on.
        if ($status === 503 && $code === 'maintenance') {
            AccountState::maintenance((string) ($response->json('message') ?? ''));
        }

        return new CentralApiException(
            $this->rejectionMessage($response, $this->fallbackMessage($status)),
            CentralApiException::kindForStatus($status),
            $status,
            (array) ($response->json('errors') ?? []),
            (array) ($response->json('data') ?? []),
        );
    }

    private function fallbackMessage(int $status): string
    {
        return match (true) {
            $status === 403 => 'You are not allowed to do this.',
            $status === 404 => 'The central server no longer has this record, or it is not visible to you.',
            $status === 410 => 'This feature was retired on the central server.',
            $status >= 500 => 'The central server had a problem. Try again later.',
            default => 'The central server refused this request.',
        };
    }

    /**
     * Logs in with the same /auth/login the web uses. Returns
     * {user: {..., permissions[]}, token}.
     */
    public function login(string $email, string $password): array
    {
        try {
            return $this->send('post', 'auth/login', null, ['email' => $email, 'password' => $password], 10)['data'] ?? [];
        } catch (CentralApiException $e) {
            if ($e->isOffline()) {
                throw new CentralApiException(
                    'Could not reach the central server. Check the internet connection and try again.',
                    CentralApiException::OFFLINE
                );
            }
            if ($e->kind === CentralApiException::UNAUTHENTICATED) {
                throw new CentralApiException('The email, username or password is incorrect.', CentralApiException::UNAUTHENTICATED, 401);
            }
            // 423: locked after 5 failed sign-ins; the server says until when.
            if ($e->status === 423) {
                throw new CentralApiException($e->getMessage(), CentralApiException::LOCKED, 423);
            }
            throw $e;
        }
    }

    /** Revokes this device's token on the server. Best effort: offline is fine. */
    public function logout(string $token): void
    {
        try {
            $this->send('post', 'auth/logout', $token, [], 5);
        } catch (\Throwable $e) {
            // Offline or already revoked: nothing more to do.
        }
    }

    /** The web address of the central server (its API URL without /api/v1). */
    public function webUrl(): string
    {
        return preg_replace('#/api/v1$#', '', $this->baseUrl());
    }

    /** The signed-in user with permissions (GET /auth/me). */
    public function me(string $token): array
    {
        return $this->send('get', 'auth/me', $token, [], 10)['data'] ?? [];
    }

    /** Barangays, events and centers for the local cache. */
    public function fetchReferenceData(string $token): array
    {
        return [
            'barangays' => $this->send('get', 'barangays', $token, [], 10)['data'] ?? [],
            'events' => $this->send('get', 'evacuation-events', $token, [], 10)['data'] ?? [],
            // detail=1: capacity, people checked in and the status shown, in one answer.
            'centers' => $this->send('get', 'evacuation-centers', $token, ['detail' => 1], 15)['data'] ?? [],
        ];
    }

    /** @return list<array{id: int, barangay_id: int, name: string}> one barangay's puroks */
    public function fetchPuroks(string $token, int $barangayRemoteId): array
    {
        return $this->send('get', 'puroks', $token, ['barangay_id' => $barangayRemoteId], 10)['data'] ?? [];
    }

    /** One center's live board figures for one event (GET .../quick-count). */
    public function fetchCenterQuickCount(string $token, int $centerRemoteId, int $eventRemoteId): array
    {
        return $this->send('get', "evacuation-centers/{$centerRemoteId}/quick-count", $token, ['evacuation_event_id' => $eventRemoteId], 10)['data'] ?? [];
    }

    /**
     * The households with someone checked in at one center for one event
     * (the web's "Already here" list).
     *
     * @return list<array>
     */
    public function fetchFamiliesAtCenter(string $token, int $centerRemoteId, int $eventRemoteId): array
    {
        return $this->send('get', "evacuation-centers/{$centerRemoteId}/families", $token, ['evacuation_event_id' => $eventRemoteId], 10)['data'] ?? [];
    }

    /**
     * Returning families (GET .../returning-families): without a source
     * event, the past events; with one, that event's re-enrollable families.
     */
    public function fetchReturningFamilies(string $token, int $centerRemoteId, int $eventRemoteId, ?int $sourceEventRemoteId = null): array
    {
        return $this->send('get', "evacuation-centers/{$centerRemoteId}/returning-families", $token, array_filter([
            'evacuation_event_id' => $eventRemoteId,
            'source_event_id' => $sourceEventRemoteId,
        ]), 15)['data'] ?? [];
    }

    /** Re-enrolls a past event's family at a center (POST .../re-enroll); returns the data block. */
    public function reEnroll(string $token, int $centerRemoteId, array $payload): array
    {
        return $this->send('post', "evacuation-centers/{$centerRemoteId}/re-enroll", $token, $payload)['data'] ?? [];
    }

    /**
     * Checks a whole family out (POST families/bulk-check-out, one family).
     * At an own-barangay host center the server also checks out a visiting
     * family. Returns that family's result row.
     */
    public function checkOutFamily(string $token, int $familyRemoteId, string $status, int $centerRemoteId, int $eventRemoteId): array
    {
        $body = $this->send('post', 'families/bulk-check-out', $token, [
            'family_ids' => [$familyRemoteId],
            'status' => $status,
            'evacuation_center_id' => $centerRemoteId,
            'evacuation_event_id' => $eventRemoteId,
        ]);

        return $body['data']['results'][0] ?? [];
    }

    /**
     * Families in private houses (GET /private-house-families): per-barangay
     * counts, and with a barangay its families with their hosts.
     */
    public function fetchPrivateHouses(string $token, int $eventRemoteId, ?int $barangayRemoteId = null): array
    {
        return $this->send('get', 'private-house-families', $token, array_filter([
            'evacuation_event_id' => $eventRemoteId,
            'barangay_id' => $barangayRemoteId,
        ]), 15)['data'] ?? [];
    }

    /** Adds a family in a private house (POST /barangays/{id}/private-house-families); returns its data. */
    public function addPrivateHouseFamily(string $token, int $barangayRemoteId, array $payload): array
    {
        return $this->send('post', "barangays/{$barangayRemoteId}/private-house-families", $token, $payload)['data'] ?? [];
    }

    // ---------------------------------------------------------------- transfers
    // All online only (docs/backend/apps-api-2026-11-transfers-capacity.md 2):
    // no client_ref; never queued.

    /** Other barangays' centers with room, nearest first (GET /transfer-requests/suggestions). */
    public function transferSuggestions(string $token, int $fromCenterRemoteId, int $persons): array
    {
        return $this->send('get', 'transfer-requests/suggestions', $token, ['from_center_id' => $fromCenterRemoteId, 'persons' => $persons], 10)['data'] ?? [];
    }

    /** Asks another barangay's center to take families (POST /transfer-requests); returns the request row. */
    public function createTransferRequest(string $token, array $payload): array
    {
        return $this->send('post', 'transfer-requests', $token, $payload)['data'] ?? [];
    }

    /** The transfer requests of an event (GET /transfer-requests). */
    public function transferRequests(string $token, int $eventRemoteId): array
    {
        return $this->send('get', 'transfer-requests', $token, ['evacuation_event_id' => $eventRemoteId], 10)['data'] ?? [];
    }

    /** Accepts or declines a request (POST /transfer-requests/{id}/accept|decline); returns the message. */
    public function decideTransfer(string $token, int $requestId, bool $accept, ?string $reason = null): string
    {
        return $this->send('post', "transfer-requests/{$requestId}/".($accept ? 'accept' : 'decline'), $token, $accept ? [] : ['reason' => $reason])['message'] ?? '';
    }

    /** Moves a family to another center now (PATCH /families/{id}/evacuation-center). */
    public function moveFamily(string $token, int $familyRemoteId, int $centerRemoteId, ?string $reason = null): array
    {
        return $this->send('patch', "families/{$familyRemoteId}/evacuation-center", $token, array_filter([
            'evacuation_center_id' => $centerRemoteId,
            'reason' => $reason,
        ]))['data'] ?? [];
    }

    /** Tells CSWD that families are arriving while the event is monitored (POST /evacuation-events/{id}/families-arriving). */
    public function familiesArriving(string $token, int $eventRemoteId, ?string $note = null): array
    {
        return $this->send('post', "evacuation-events/{$eventRemoteId}/families-arriving", $token, array_filter(['note' => $note]));
    }

    /** The account's in-app notifications (GET /notifications): {unread, notifications[]}. */
    public function notifications(string $token): array
    {
        return $this->send('get', 'notifications', $token, [], 8)['data'] ?? [];
    }

    public function markNotificationsRead(string $token): void
    {
        $this->send('post', 'notifications/read', $token, [], 8);
    }

    /** Sends one fully registered family (POST /families/register); returns its id. */
    public function registerFamily(string $token, array $payload): int
    {
        return (int) ($this->send('post', 'families/register', $token, $payload)['data']['id'] ?? 0);
    }

    /**
     * Sends one EC Board quick add (POST .../evacuees). The response's
     * data is the household (family); the new person's id is evacuee_id,
     * beside data.
     *
     * @return array{evacuee_id: int, family_id: int}
     */
    public function addEvacuee(string $token, int $centerRemoteId, array $payload): array
    {
        $body = $this->send('post', "evacuation-centers/{$centerRemoteId}/evacuees", $token, $payload);

        return [
            'evacuee_id' => (int) ($body['evacuee_id'] ?? 0),
            'family_id' => (int) ($body['data']['id'] ?? 0),
        ];
    }

    /**
     * The server's message, then each field error it doesn't already say
     * (the server often repeats the message under the field it is about).
     */
    private function rejectionMessage(Response $response, string $fallback): string
    {
        $message = $response->json('message') ?? $fallback;
        $extra = collect($response->json('errors') ?? [])->except('code')->flatten()
            ->reject(fn ($error) => ! is_string($error) || $error === $message)
            ->unique();

        return $extra->isEmpty() ? $message : $message.' '.$extra->implode(' ');
    }
}
