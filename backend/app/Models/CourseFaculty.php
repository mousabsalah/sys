<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseFaculty extends Model
{
    protected $table = 'course_faculty';

    public $timestamps = false;

    protected $fillable = ['course_id', 'faculty_id', 'assigned_at'];

    protected $casts = ['assigned_at' => 'datetime'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class, 'faculty_id');
    }
}
