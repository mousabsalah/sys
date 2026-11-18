<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ExamSession extends Model
{
    protected $fillable = [
        'course_id', 'group_id', 'hall_id', 'exam_date', 'start_time', 'end_time', 'exam_type', 'academic_term', 'semester_id', 'status',
    ];

    /** Scope: الامتحانات المعتمدة فقط — يرى غير المجلس/المدير المعتمدة دون غيرها */
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.status', 'approved');
    }

    /** Scope: الامتحانات المعلّقة (بانتظار اعتماد المدير) */
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.status', 'pending');
    }

    protected $casts = ['exam_date' => 'date'];

    /**
     * Scope: الامتحانات غير المرتبطة بفصل مؤرشف — الأرشفة تجمّد الفصل ولا يظل يحجب الجدولة.
     * وصفوف «مؤرشفة» صراحةً (status='archived') تُستبعد هي أيضًا من كل ما هو نشط.
     */
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('exam_sessions.status', '!=', 'archived')->where(function (Builder $q) {
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
