<?php

namespace App\Models;

use App\Models\Concerns\HasClientRef;
use App\Support\Format;
use Illuminate\Database\Eloquent\Model;

/**
 * One file with personal information saved, printed or exported from this
 * app. Reported to the server's activity log through the queue
 * (POST /file-downloads with client_ref); reported_at is set once it is in.
 */
class DownloadLog extends Model
{
    use HasClientRef;

    /** Our documents as the server's file_type codes (docs/backend/apps-api-2026-11-24.md 4). */
    public const FILE_TYPES = [
        'ec-board' => 'ec_information_board',
        'ec-board-official' => 'ec_information_board',
        'family-list' => 'evacuees',
        'relief-sheet' => 'relief_families',
        'families-to-evacuate' => 'families_to_evacuate',
        'waiting-records' => 'waiting_records',
    ];

    protected $fillable = [
        'document', 'format', 'file_name', 'evacuation_event_remote_id', 'evacuation_center_remote_id',
        'user_remote_id', 'user_name', 'saved_to', 'generated_offline', 'reported_at',
        'client_ref', 'file_type', 'action', 'report_remote_id', 'sync_error', 'sync_state',
    ];

    protected $casts = ['generated_offline' => 'boolean', 'reported_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (DownloadLog $log) {
            $log->file_type ??= self::FILE_TYPES[$log->document] ?? 'other';
        });
    }

    /** The body of POST /file-downloads. The barangay is left out: an official's own. */
    public function toSyncPayload(): array
    {
        return array_filter([
            'file_type' => $this->file_type ?: 'other',
            'file_format' => preg_match('/^[a-z0-9]{1,10}$/', (string) $this->format) ? $this->format : null,
            'action' => in_array($this->action, ['saved', 'shared', 'printed'], true) ? $this->action : 'saved',
            'evacuation_event_id' => $this->evacuation_event_remote_id,
            'report_id' => $this->report_remote_id,
            'saved_at' => $this->created_at->copy()->timezone(Format::TIMEZONE)->toIso8601String(),
            'client_ref' => $this->client_ref,
        ], fn ($v) => $v !== null);
    }
}
