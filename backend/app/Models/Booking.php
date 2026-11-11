<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Booking extends Model
{
    protected $fillable = [
        'hall_id', 'date', 'start_time', 'end_time', 'course_id', 'activity_name', 'user_id', 'status', 'semester_id',
    ];

    protected $casts = ['date' => 'date'];

    /**
     * Scope: الحجوزات المؤكدة وغير المرتبطة بفصل مؤرشف.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'confirmed')
            ->where(function (Builder $q) {
                $q->whereNull('bookings.semester_id')
                    ->orWhereExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('semesters')
                            ->whereColumn('semesters.id', 'bookings.semester_id')
                            ->whereNull('semesters.archived_at');
                    });
            });
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }
}
