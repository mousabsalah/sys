<?php

namespace App\Services\Academic;

use App\Models\Semester;

/**
 * SemesterResolver — حسم الفصل النشط (غير مؤرشف) لدفعة معينة.
 * عند إنشاء محاضرة/امتحان جديد يُربط بالفصل النشط لدفعته — حتى تعمل الأرشفة والأرشفة الفارغة.
 */
final class SemesterResolver
{
    public static function activeForBatch(int $batchId): ?int
    {
        return Semester::where('batch_id', $batchId)
            ->whereNull('archived_at')
            ->orderByDesc('id')
            ->value('id');
    }
}
