<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Period extends Model
{
    protected $fillable = ['program_id', 'day_of_week', 'start_time', 'end_time', 'label', 'sort_order'];
}
