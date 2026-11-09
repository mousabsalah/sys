<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamUpdate extends Model
{
    public $timestamps = false;

    protected $fillable = ['group_id', 'exam_session_id', 'update_type', 'description'];

    protected $casts = ['created_at' => 'datetime'];
}
