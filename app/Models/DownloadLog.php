<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One file with personal information saved from this app (see the migration). */
class DownloadLog extends Model
{
    protected $fillable = [
        'document', 'format', 'file_name', 'evacuation_event_remote_id', 'evacuation_center_remote_id',
        'user_remote_id', 'user_name', 'saved_to', 'generated_offline', 'reported_at',
    ];

    protected $casts = ['generated_offline' => 'boolean', 'reported_at' => 'datetime'];
}
