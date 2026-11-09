<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Timetable extends Model
{
    protected $fillable = [
        'course_id', 'faculty_id', 'group_id', 'hall_id',
        'day_of_week', 'start_time', 'end_time', 'academic_term',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function faculty()
    {
        return $this->belongsTo(Faculty::class, 'faculty_id');
    }

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function hall()
    {
        return $this->belongsTo(Hall::class);
    }
}
