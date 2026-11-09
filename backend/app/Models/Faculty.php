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

    protected $fillable = ['full_name', 'department_id', 'email', 'phone'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_faculty', 'faculty_id', 'course_id');
    }
}
