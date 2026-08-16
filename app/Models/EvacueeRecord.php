<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvacueeRecord extends Model
{
    protected $fillable = ['remote_id', 'head_name', 'barangay_name', 'member_count'];
}
