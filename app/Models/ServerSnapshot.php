<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A read-only copy of one server answer (a Monitoring section, Final counts). */
class ServerSnapshot extends Model
{
    protected $fillable = ['key', 'payload', 'fetched_at'];

    protected $casts = ['payload' => 'array', 'fetched_at' => 'datetime'];

    public static function keep(string $key, array $payload): self
    {
        return static::updateOrCreate(['key' => $key], ['payload' => $payload, 'fetched_at' => now()]);
    }

    public static function named(string $key): ?self
    {
        return static::firstWhere('key', $key);
    }
}
