<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacultyEntity extends Model
{
    protected $table = 'faculties';

    protected $fillable = ['name', 'code', 'description'];

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'faculty_id');
    }
}
