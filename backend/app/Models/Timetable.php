<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Timetable extends Model
{
    protected $fillable = [
        'course_id', 'faculty_id', 'group_id', 'hall_id',
        'day_of_week', 'start_time', 'end_time', 'academic_term', 'semester_id', 'status',
    ];

    /** Scope: الجداول المعتمدة فقط — يرى غير المجلس/المدير المعتمدة دون غيرها */
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.status', 'approved');
    }

    /** Scope: الجداول المعلّقة (بانتظار اعتماد المدير) */
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.status', 'pending');
    }

    /**
     * Scope: المحاضرات غير المرتبطة بفصل مؤرشف — الأرشفة تجمّد الفصل ولا يظل يحجب الجدولة.
     * وصفوف «مؤرشفة» صراحةً (status='archived') تُستبعد هي أيضًا من كل ما هو نشط.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('timetables.status', '!=', 'archived')->where(function (Builder $q) {
            $q->whereNull('timetables.semester_id')
                ->orWhereExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('semesters')
                        ->whereColumn('semesters.id', 'timetables.semester_id')
                        ->whereNull('semesters.archived_at');
                });
        });
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class, 'faculty_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }
}
