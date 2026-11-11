<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** @return BelongsTo<FacultyEntity, $this> */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(FacultyEntity::class, 'faculty_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** @return HasMany<Timetable, $this> */
    public function timetables(): HasMany
    {
        return $this->hasMany(Timetable::class);
    }

    /** @return HasMany<ExamSession, $this> */
    public function examSessions(): HasMany
    {
        return $this->hasMany(ExamSession::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
