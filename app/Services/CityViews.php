<?php

namespace App\Services;

use App\Exceptions\CentralApiException;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\ServerSnapshot;

/**
 * CSWD's read-only pages on the desktop, fetched when online and kept for
 * offline reading: Monitoring (GET /monitoring/{section}) and Final counts
 * (GET /count-submissions, /count-submissions/barangays). Validation and
 * every other action stay on the web.
 */
class CityViews
{
    public const MONITORING_SECTIONS = ['overview', 'centers', 'vulnerable', 'requests'];

    public function __construct(private CentralApiService $api) {}

    /** Fetches every page this account may read. Stops at no connection or a dead login. */
    public function refresh(LocalAuth $auth): void
    {
        if ($auth->can('page.monitoring')) {
            foreach (self::MONITORING_SECTIONS as $section) {
                $this->fetch("monitoring.{$section}", fn () => $this->api->send('get', "monitoring/{$section}", $auth->api_token, [], 15)['data'] ?? []);
            }
        }

        if ($auth->can('count_submissions.review')) {
            $this->refreshFinalCounts($auth);
        }
    }

    public function refreshFinalCounts(LocalAuth $auth): void
    {
        $event = $this->currentEvent();
        if (! $event) {
            ServerSnapshot::keep('final_counts', ['evacuation_event' => null, 'barangays' => [], 'submissions' => []]);

            return;
        }

        $this->fetch('final_counts', function () use ($auth, $event) {
            $barangays = $this->api->send('get', 'count-submissions/barangays', $auth->api_token, ['evacuation_event_id' => $event->remote_id], 15)['data'] ?? [];
            $waiting = $this->api->send('get', 'count-submissions', $auth->api_token, [
                'evacuation_event_id' => $event->remote_id, 'status' => 'pending', 'per_page' => 200,
            ], 15)['data']['data'] ?? [];

            return [
                'evacuation_event' => $barangays['evacuation_event'] ?? ['id' => $event->remote_id, 'name' => $event->name, 'is_demo' => $event->is_demo],
                'barangays' => $barangays['barangays'] ?? [],
                'submissions' => $waiting,
            ];
        });
    }

    /**
     * The event Final counts follows: the newest active one, else the
     * newest monitored one; a real event before a demo one (the server's
     * own rule for "the current event").
     */
    public function currentEvent(): ?EvacuationEvent
    {
        // The event the user works on (the top-bar event switcher).
        return app(SelectedEvent::class)->get();
    }

    private function fetch(string $key, callable $load): void
    {
        try {
            ServerSnapshot::keep($key, $load());
        } catch (CentralApiException $e) {
            if ($e->isOffline() || $e->kind === CentralApiException::UNAUTHENTICATED) {
                throw $e;
            }
            // Refused or gone: keep the last copy.
        }
    }
}
