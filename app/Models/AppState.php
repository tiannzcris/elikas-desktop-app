<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Key/value facts about this device (JSON values). */
class AppState extends Model
{
    protected $table = 'app_state';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::find($key);

        return $row ? json_decode($row->value, true) : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
    }

    public static function forget(string $key): void
    {
        static::whereKey($key)->delete();
    }
}
