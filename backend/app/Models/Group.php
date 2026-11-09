<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $fillable = ['batch_id', 'track', 'student_count'];

    protected $casts = ['student_count' => 'integer'];

    public function batch()
    {
        return $this->belongsTo(Batch::class);
    }
}
