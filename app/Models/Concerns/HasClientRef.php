<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/** Gives every new queued record its own client_ref (a UUID) for duplicate-safe retries. */
trait HasClientRef
{
    public static function bootHasClientRef(): void
    {
        static::creating(function ($model) {
            $model->client_ref ??= (string) Str::uuid();
        });
    }
}
