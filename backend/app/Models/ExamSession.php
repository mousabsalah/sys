<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamSession extends Model
{
    protected $fillable = [
        'course_id', 'group_id', 'hall_id', 'exam_date', 'start_time', 'end_time', 'exam_type', 'academic_term',
    ];

    protected $casts = ['exam_date' => 'date'];
}
