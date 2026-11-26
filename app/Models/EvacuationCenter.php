<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvacuationCenter extends Model
{
    protected $fillable = [
        'remote_id', 'barangay_remote_id', 'name', 'status', 'approval_status',
        'capacity_persons', 'current_occupancy', 'effective_status', 'capacity_source', 'capacity_basis',
        'total_occupancy', 'occupancy_note',
    ];

    /** Tooltips for an estimated capacity (docs/backend/apps-api-2026-11-transfers-capacity.md 3.2). */
    public const CAPACITY_BASIS = [
        'peak_2025' => 'Estimated from the 2025 peak',
        'type_median' => 'Estimated: median of the same type of center',
    ];

    /** No capacity at all: never full, never a percentage. */
    public function capacityUnknown(): bool
    {
        return $this->capacity_source === 'unknown' || ! $this->capacity_persons;
    }

    public function capacityEstimated(): bool
    {
        return ! $this->capacityUnknown() && $this->capacity_source === 'estimated';
    }

    /** The tooltip of an estimated capacity, or null. */
    public function capacityTooltip(): ?string
    {
        return $this->capacityEstimated() ? (self::CAPACITY_BASIS[$this->capacity_basis] ?? 'Estimated') : null;
    }

    /**
     * "48 / 112", "48 / 112 est." or "48 / – (capacity unknown)": the people
     * here for the selected event, or everyone inside ($everyone).
     */
    public function occupancyLabel(bool $everyone = false): string
    {
        $inside = number_format($everyone ? $this->occupancyTotal() : $this->occupancyHere());

        return match (true) {
            $this->capacityUnknown() => "{$inside} / – (capacity unknown)",
            $this->capacityEstimated() => "{$inside} / ".number_format($this->capacity_persons).' est.',
            default => "{$inside} / ".number_format($this->capacity_persons),
        };
    }

    /** At this share of capacity a center with people inside shows as full (server: FULL_AT_PERCENT). */
    public const FULL_AT_PERCENT = 90;

    /** Status labels; each is shown beside its state symbol (components/center-symbol), as on the web. */
    public const STATUSES = [
        'active' => 'Active',
        'on_standby' => 'On standby',
        'nearly_full' => 'Nearly full',
        'full' => 'Full',
        'closed' => 'Closed',
    ];

    /*
     * Every figure follows one rule (docs/backend/apps-api-2026-11-center-figures-per-event.md 1):
     * the number shown is the people here for the selected event; Full and
     * "time to ask for space" count everyone inside, all open events of the
     * same kind. The cached server figures (current_occupancy,
     * total_occupancy, effective_status) are for the event selected at the
     * last refresh or switch; this device adds the arrivals it has not sent.
     */

    /**
     * Arrivals at this center waiting on this device (EC Board entries,
     * returning families, check-ins of listed families): for $event only,
     * or ($sameKind) for every open event of the same kind as $event (a
     * practice event only with practice events, a real one with real ones).
     */
    public function unsentArrivals(?EvacuationEvent $event, bool $sameKind = false): int
    {
        $events = match (true) {
            $sameKind => EvacuationEvent::where('status', '!=', 'closed')
                ->when($event, fn ($q) => $q->where('is_demo', (bool) $event->is_demo))->pluck('id'),
            $event !== null && ! $event->isClosed() => collect([$event->id]),
            default => collect(),
        };
        if ($events->isEmpty()) {
            return 0;
        }

        $pending = EcBoardEntry::where('evacuation_center_id', $this->id)->whereNull('synced_at')
            ->whereIn('evacuation_event_id', $events)->count();
        $returning = PendingReEnrollment::where('evacuation_center_id', $this->id)->whereNull('synced_at')
            ->whereIn('evacuation_event_id', $events)->get()
            ->sum(fn (PendingReEnrollment $r) => count($r->members));
        $listed = PendingListCheckIn::where('evacuation_center_id', $this->id)->whereNull('synced_at')
            ->whereIn('evacuation_event_id', $events)->sum('members_count');

        return $pending + $returning + (int) $listed;
    }

    /** People here for the selected event as far as this device knows: the server's count plus this event's arrivals not yet sent. */
    public function occupancyHere(): int
    {
        return (int) $this->current_occupancy + $this->unsentArrivals(EvacuationEvent::working());
    }

    /**
     * Everyone inside, all open events of the same kind (total_occupancy;
     * from an older server without it, the event's own count), plus the
     * unsent arrivals of those events. What Full and needsSpace() go by.
     */
    public function occupancyTotal(): int
    {
        $server = $this->total_occupancy ?? $this->current_occupancy;

        return (int) $server + $this->unsentArrivals(EvacuationEvent::working(), true);
    }

    /** At or above 90% of a known capacity, counting everyone inside. */
    private function fullByCount(): bool
    {
        $total = $this->occupancyTotal();

        return $total > 0 && ! $this->capacityUnknown() && $total * 100 >= self::FULL_AT_PERCENT * $this->capacity_persons;
    }

    /**
     * The status shown: the server's effective_status for the selected
     * event (active, on standby, closed; nearly full or full counted on
     * everyone inside). This device only adds what the server does not know
     * yet: unsent arrivals that make the center full, or that make an
     * on-standby center active (arrivals for the selected event only).
     * From an older server without effective_status: active with people here.
     */
    public function displayStatus(): string
    {
        $server = $this->effective_status
            ?: ((int) $this->current_occupancy > 0 ? 'active' : ($this->status === 'closed' ? 'closed' : 'on_standby'));
        if ($server === 'closed') {
            return 'closed';
        }

        // An unknown capacity is never full (the server's rule).
        if ($this->fullByCount()) {
            return 'full';
        }
        if (in_array($server, ['full', 'nearly_full'], true) && $this->capacityUnknown()) {
            return 'active';
        }
        if ($server === 'on_standby' && $this->unsentArrivals(EvacuationEvent::working()) > 0) {
            return 'active';
        }

        return $server;
    }

    /** In use: people inside (active, nearly full or full). */
    public function inUse(): bool
    {
        return in_array($this->displayStatus(), ['active', 'nearly_full', 'full'], true);
    }

    /** At or above 90% of a known capacity, everyone inside: time to ask another center for space. */
    public function needsSpace(): bool
    {
        return $this->fullByCount();
    }

    /** Whole percent of a known capacity taken by everyone inside (the "is at 92% of its capacity" banner). */
    public function percentTaken(): ?int
    {
        return $this->capacityUnknown() ? null : (int) floor($this->occupancyTotal() * 100 / $this->capacity_persons);
    }

    /**
     * Stores the server's center rows (GET /evacuation-centers?detail=1) in
     * the local cache: what officials set, the capacity, and the figures
     * for the event the request named. Rejected centers, and for an
     * official other barangays' centers, are left out.
     *
     * @return list<int> the remote ids stored
     */
    public static function storeFromServer(array $rows, ?int $ownBarangay = null): array
    {
        $stored = [];
        foreach ($rows as $c) {
            $barangayId = (int) ($c['barangay_id'] ?? $c['barangay']['id'] ?? 0);
            if (($c['approval_status'] ?? 'approved') === 'rejected'
                || ($ownBarangay && $barangayId !== $ownBarangay)) {
                continue;
            }
            self::updateOrCreate(['remote_id' => $c['id']], [
                'barangay_remote_id' => $barangayId,
                'name' => $c['name'],
                'status' => $c['status'],
                'approval_status' => $c['approval_status'] ?? 'approved',
                'capacity_persons' => $c['capacity_persons'] ?? null,
                'capacity_source' => $c['capacity_source'] ?? (empty($c['capacity_persons']) ? 'unknown' : 'official'),
                'capacity_basis' => $c['capacity_basis'] ?? null,
                'current_occupancy' => $c['current_occupancy'] ?? null,
                'effective_status' => $c['effective_status'] ?? null,
                'total_occupancy' => $c['total_occupancy'] ?? null,
                'occupancy_note' => $c['occupancy_note'] ?? null,
            ]);
            $stored[] = $c['id'];
        }

        return $stored;
    }

    /** Added by the barangay and not yet approved by CSWD. Usable by its own barangay. */
    public function isUnverified(): bool
    {
        return $this->approval_status === 'pending';
    }

    /**
     * Keyed off barangay_remote_id -> barangays.remote_id, not the usual
     * local-id foreign key -- evacuation_centers is a read-only cache of
     * central records, so it stores the barangay it belongs to by the
     * SAME remote id every other cross-reference in this cache table
     * uses (see FamilyController::centerSummary()'s own note on why this
     * matters: a center's OWN barangay can differ from the barangay
     * whose families are currently staying there).
     */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'barangay_remote_id', 'remote_id');
    }
}
