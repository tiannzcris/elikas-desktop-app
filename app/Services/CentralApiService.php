<?php

namespace App\Services;

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
     */
    public function fetchEvacuees(string $token): array
    {
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        try {
            $response = Http::withHeaders($headers)->timeout(10)->get("{$this->baseUrl()}/families");
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Could not reach the central server to refresh the evacuee list. Check your internet connection.');
        }

        if (! $response->successful()) {
            throw new \RuntimeException('The central server rejected the request -- your login may have expired. Try logging in again while online.');
        }

        return $response->json('data');
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
}
