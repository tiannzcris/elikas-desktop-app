<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalAuth extends Model
{
    protected $table = 'local_auth';

    protected $fillable = [
        'remote_user_id', 'name', 'email', 'role', 'barangay_id',
        'barangay_name', 'api_token', 'logged_in_at',
    ];

    protected $hidden = ['api_token'];

    protected $casts = [
        'logged_in_at' => 'datetime',
    ];

    /**
     * There's ever only one row -- whoever is currently logged in on this
     * device. Every part of the app should read the current user through
     * this accessor rather than querying the table directly.
     */
    public static function current(): ?self
    {
        return static::query()->latest('logged_in_at')->first();
    }

    public function isBarangayOfficial(): bool
    {
        return $this->role === 'barangay_official';
    }
}
