<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuardLock extends Model
{
    public $timestamps = false;

    protected $fillable = ['hall_id', 'user_id', 'action', 'action_at', 'notes'];

    protected $casts = ['action_at' => 'datetime'];

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }
}
