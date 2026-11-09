<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    protected $fillable = ['name', 'description'];

    public function periods()
    {
        return $this->hasMany(Period::class, 'program_id')->orderBy('sort_order');
    }

    public function batches()
    {
        return $this->hasMany(Batch::class, 'program_id');
    }
}
