<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Semester extends Model
{
    protected $table = 'semesters';

    protected $fillable = [
        'faculty_id',
        'department_id',
        'batch_id',
        'academic_term',
        'start_date',
        'end_date',
        'archived_at',
    ];

    protected $casts = [
        'faculty_id' => 'integer',
        'department_id' => 'integer',
        'batch_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'archived_at' => 'datetime',
    ];

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(FacultyEntity::class, 'faculty_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
