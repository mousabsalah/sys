<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SubstituteRequest — طلب مدرس آخر أن يغطي محاضرته (تواصل مباشر بين مدرسين).
 * الطالب ينشئ الطلب ← المستهدف يقبل أو يرفض. عند القبول يُعاد إسناد المحاضرة للبديل
 * (سجل audit في audit_logs + timetable_updates).
 */
class SubstituteRequest extends Model
{
    protected $fillable = ['timetable_id', 'requested_by_lecturer_id', 'substitute_lecturer_id', 'status'];

    public function lecture(): BelongsTo
    {
        return $this->belongsTo(Timetable::class, 'timetable_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Faculty::class, 'requested_by_lecturer_id');
    }

    public function substitute(): BelongsTo
    {
        return $this->belongsTo(Faculty::class, 'substitute_lecturer_id');
    }
}
