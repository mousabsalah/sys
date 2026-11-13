<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Faculty extends Model
{
    use SoftDeletes;

    protected $table = 'faculty';

    protected $fillable = ['full_name', 'degree', 'department_id', 'email', 'phone'];

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsToMany<FacultyEntity, $this> */
    public function faculties(): BelongsToMany
    {
        return $this->belongsToMany(FacultyEntity::class, 'lecturer_faculties', 'lecturer_id', 'faculty_id')->withTimestamps();
    }

    /** @return BelongsToMany<Course, $this> */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_faculty', 'faculty_id', 'course_id');
    }
}
