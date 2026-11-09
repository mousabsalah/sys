<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    protected $fillable = ['name', 'description'];

    public function periods(): HasMany
    {
        return $this->hasMany(Period::class, 'program_id')->orderBy('sort_order');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class, 'program_id');
    }
}
