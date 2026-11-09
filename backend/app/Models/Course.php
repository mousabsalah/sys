<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'credit_hours', 'required_equipment'];

    protected $casts = ['credit_hours' => 'integer'];

    public function facultyMembers(): BelongsToMany
    {
        return $this->belongsToMany(Faculty::class, 'course_faculty', 'course_id', 'faculty_id');
    }
}
