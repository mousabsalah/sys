<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupCourse extends Model
{
    protected $table = 'group_courses';

    protected $fillable = ['group_id', 'course_id', 'sessions_per_week'];

    protected $casts = ['sessions_per_week' => 'integer'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
