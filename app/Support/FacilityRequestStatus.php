<?php

namespace App\Support;

/**
 * Facility requests: Waiting -> Approved -> Sent -> Received, or Rejected
 * (docs/backend/apps-api-2026-11-22.md 5). The old "fulfilled" is read as
 * Received (the server turned old rows into received).
 */
final class FacilityRequestStatus
{
    public const LABELS = [
        'pending' => ['Waiting', 'badge-warning'],
        'approved' => ['Approved', 'badge-info'],
        'sent' => ['Sent', 'badge-info'],
        'received' => ['Received', 'badge-success'],
        'fulfilled' => ['Received', 'badge-success'],
        'rejected' => ['Rejected', 'badge-danger'],
    ];

    /** @return array{0: string, 1: string} label and badge tone */
    public static function of(?string $status): array
    {
        return self::LABELS[$status] ?? [\Illuminate\Support\Str::headline((string) $status), 'badge-neutral'];
    }

    /** "Approved 6 of 10" when fewer were approved than asked, else null. */
    public static function approvedOf(array $request): ?string
    {
        $asked = $request['quantity'] ?? $request['quantity_requested'] ?? null;
        $approved = $request['quantity_approved'] ?? null;
        if ($asked === null || $approved === null || (int) $approved >= (int) $asked) {
            return null;
        }
        $unit = trim((string) ($request['unit'] ?? ''));

        return 'Approved '.number_format((int) $approved).' of '.number_format((int) $asked).($unit !== '' ? " {$unit}" : '');
    }
}
