<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacultyEntity extends Model
{
    use HasFactory;

    protected $table = 'faculties';

    protected $fillable = ['name', 'code', 'description'];

    public function departments()
    {
        return $this->hasMany(Department::class, 'faculty_id');
    }
}
