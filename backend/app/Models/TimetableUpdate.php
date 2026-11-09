<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimetableUpdate extends Model
{
    public $timestamps = false;

    protected $fillable = ['group_id', 'timetable_id', 'update_type', 'description'];

    protected $casts = ['created_at' => 'datetime'];
}
