<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvacuationCenter extends Model
{
    protected $fillable = ['remote_id', 'barangay_remote_id', 'name', 'status'];
}
