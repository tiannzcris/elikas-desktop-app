<?php

namespace App\Services;

use App\Exceptions\CentralApiAuthenticationException;
use App\Exceptions\CentralApiException;
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
        $request = Http::acceptJson()->timeout($timeout);
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
            return $response->json() ?? [];
        }

        throw $this->failure($response);
    }

    private function failure(Response $response): CentralApiException
    {
        $status = $response->status();

        if ($status === 401) {
            return new CentralApiAuthenticationException;
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
                throw new CentralApiException('The email or password is incorrect.', CentralApiException::UNAUTHENTICATED, 401);
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
            'centers' => $this->send('get', 'evacuation-centers', $token, [], 10)['data'] ?? [],
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
        $extra = collect($response->json('errors') ?? [])->flatten()
            ->reject(fn ($error) => ! is_string($error) || $error === $message)
            ->unique();

        return $extra->isEmpty() ? $message : $message.' '.$extra->implode(' ');
    }
}
