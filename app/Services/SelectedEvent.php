<?php

namespace App\Services;

use App\Exceptions\CentralApiException;
use App\Models\AppState;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Support\Collection;

/**
 * The event the user works on (server: GET/PUT /selected-event, and
 * selected_evacuation_event on login and /auth/me). The server remembers it
 * per user, so the web and the desktop share it. Kept here too, so every
 * screen works offline; a choice made offline is sent when the server
 * answers again.
 *
 * With nothing chosen, the server's own rule picks: the only open event,
 * else a real event before a demo one, an active one before one in
 * monitoring, the newest.
 */
class SelectedEvent
{
    private const KEY = 'selected_event';

    public function __construct(private CentralApiService $api) {}

    /** The event to work on, or null when no event is known at all. */
    public function get(): ?EvacuationEvent
    {
        $state = AppState::get(self::KEY);
        if (! empty($state['remote_id']) && ($event = EvacuationEvent::firstWhere('remote_id', $state['remote_id']))) {
            return $event;
        }

        return $this->pick();
    }

    public function chosen(): bool
    {
        return ! empty(AppState::get(self::KEY)['remote_id']);
    }

    /** The server's rule when nothing is chosen. */
    public function pick(): ?EvacuationEvent
    {
        return EvacuationEvent::where('status', '!=', 'closed')->get()->sortBy(fn (EvacuationEvent $e) => [
            $e->is_demo ? 1 : 0,
            $e->status === 'active' ? 0 : 1,
            -($e->start_date?->getTimestamp() ?? 0),
            -$e->remote_id,
        ])->first();
    }

    /** @return array{open: Collection, closed: Collection} the events to switch between */
    public function options(): array
    {
        $all = EvacuationEvent::orderByDesc('start_date')->orderByDesc('remote_id')->get();

        return ['open' => $all->where('status', '!=', 'closed')->values(), 'closed' => $all->where('status', 'closed')->values()];
    }

    /**
     * Works on this event from now on (null: let the server pick again) and
     * tells the server when it can be reached.
     */
    public function choose(?EvacuationEvent $event, ?LocalAuth $auth = null): void
    {
        AppState::put(self::KEY, ['remote_id' => $event?->remote_id, 'unsent' => true]);
        if ($auth) {
            $this->push($auth);
        }
    }

    /** Sends a choice made on this device (offline earlier) to the server. */
    public function push(LocalAuth $auth): void
    {
        $state = AppState::get(self::KEY);
        if (empty($state['unsent'])) {
            return;
        }
        try {
            $this->api->send('put', 'selected-event', $auth->api_token, ['evacuation_event_id' => $state['remote_id'] ?? null], 8);
            AppState::put(self::KEY, ['remote_id' => $state['remote_id'] ?? null, 'unsent' => false]);
        } catch (CentralApiException $e) {
            // Offline or refused: kept, sent next time.
        }
    }

    /**
     * Takes the server's selection (login, /auth/me) unless a choice made
     * here is still waiting to be sent.
     */
    public function fromServer(?array $selected, bool $chosen = true): void
    {
        if (! empty(AppState::get(self::KEY)['unsent'])) {
            return;
        }
        AppState::put(self::KEY, ['remote_id' => $chosen ? ($selected['id'] ?? null) : null, 'unsent' => false]);
    }
}
