<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ExamSession extends Model
{
    protected $fillable = [
        'course_id', 'group_id', 'hall_id', 'exam_date', 'start_time', 'end_time', 'exam_type', 'academic_term', 'semester_id',
    ];

    protected $casts = ['exam_date' => 'date'];

    /**
     * Scope: الامتحانات غير المرتبطة بفصل مؤرشف — الأرشفة تجمّد الفصل ولا يظل يحجب الجدولة.
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('exam_sessions.semester_id')
                ->orWhereExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('semesters')
                        ->whereColumn('semesters.id', 'exam_sessions.semester_id')
                        ->whereNull('semesters.archived_at');
                });
        });
    }
}
