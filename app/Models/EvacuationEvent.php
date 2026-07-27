<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvacuationEvent extends Model
{
    protected $fillable = ['remote_id', 'name', 'event_type', 'status'];
}
