<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = ['faculty_id', 'name', 'code'];

    public function faculty()
    {
        return $this->belongsTo(FacultyEntity::class, 'faculty_id');
    }

    public function batches()
    {
        return $this->hasMany(Batch::class, 'department_id');
    }
}
