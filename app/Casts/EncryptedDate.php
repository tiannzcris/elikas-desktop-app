<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** A date kept encrypted at rest (a birth date): read back as a Carbon date. */
class EncryptedDate implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse(Model::currentEncrypter()->decryptString($value))->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value);

        return Model::currentEncrypter()->encryptString($date->format('Y-m-d'));
    }
}
