<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseFaculty extends Model
{
    protected $table = 'course_faculty';

    public $timestamps = false;

    protected $fillable = ['course_id', 'faculty_id', 'assigned_at'];

    protected $casts = ['assigned_at' => 'datetime'];
}
