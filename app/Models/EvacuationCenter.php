<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvacuationCenter extends Model
{
    protected $fillable = [
        'remote_id', 'barangay_remote_id', 'name', 'status', 'approval_status',
        'capacity_persons', 'current_occupancy', 'effective_status', 'capacity_source', 'capacity_basis',
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

    /** "48 / 112", "48 / 112 est." or "48 / – (capacity unknown)". */
    public function occupancyLabel(): string
    {
        $inside = number_format($this->occupancyHere());

        return match (true) {
            $this->capacityUnknown() => "{$inside} / – (capacity unknown)",
            $this->capacityEstimated() => "{$inside} / ".number_format($this->capacity_persons).' est.',
            default => "{$inside} / ".number_format($this->capacity_persons),
        };
    }

    /** At this share of capacity a center with people inside shows as full (server: FULL_AT_PERCENT). */
    public const FULL_AT_PERCENT = 90;

    /** Status labels and chip tones, as on the web (active green, nearly full amber, standby blue, full red, closed dark gray). */
    public const STATUSES = [
        'active' => ['Active', 'badge-success'],
        'on_standby' => ['On standby', 'badge-info'],
        'nearly_full' => ['Nearly full', 'badge-warning'],
        'full' => ['Full', 'badge-danger'],
        'closed' => ['Closed', 'badge-dark'],
    ];

    /** People in this center as far as this device knows: the server's count plus arrivals not yet sent. */
    public function occupancyHere(): int
    {
        $pending = EcBoardEntry::where('evacuation_center_id', $this->id)->whereNull('synced_at')
            ->whereHas('evacuationEvent', fn ($q) => $q->where('status', '!=', 'closed'))->count();
        $returning = PendingReEnrollment::where('evacuation_center_id', $this->id)->whereNull('synced_at')
            ->whereHas('evacuationEvent', fn ($q) => $q->where('status', '!=', 'closed'))->get()
            ->sum(fn (PendingReEnrollment $r) => count($r->members));

        return (int) $this->current_occupancy + $pending + $returning;
    }

    /**
     * The status shown, by the server's rule (EvacuationCenter::effectiveStatusOf):
     * people inside make a center active, nearly full when the server says
     * so, or full from 90% of capacity; an empty center shows its stored
     * status (what officials set).
     */
    public function displayStatus(): string
    {
        $inside = $this->occupancyHere();
        // An unknown capacity is never full (the server's rule).
        if ($inside > 0 && ! $this->capacityUnknown() && $inside * 100 >= self::FULL_AT_PERCENT * $this->capacity_persons) {
            return 'full';
        }

        // Nearly full is the server's call (effective_status); a center with
        // an unknown capacity is never nearly full either.
        if ($inside > 0 && ! $this->capacityUnknown() && $this->effective_status === 'nearly_full') {
            return 'nearly_full';
        }

        return $inside > 0 ? 'active' : ($this->status ?: 'on_standby');
    }

    /** In use: people inside (active, nearly full or full). */
    public function inUse(): bool
    {
        return in_array($this->displayStatus(), ['active', 'nearly_full', 'full'], true);
    }

    /** At or above 90% of a known capacity: time to ask another center for space. */
    public function needsSpace(): bool
    {
        return ! $this->capacityUnknown() && $this->occupancyHere() * 100 >= self::FULL_AT_PERCENT * $this->capacity_persons;
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
