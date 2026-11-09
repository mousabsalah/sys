<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = [
        'hall_id', 'date', 'start_time', 'end_time', 'course_id', 'activity_name', 'user_id', 'status',
    ];

    protected $casts = ['date' => 'date'];

    public function hall()
    {
        return $this->belongsTo(Hall::class);
    }
}
