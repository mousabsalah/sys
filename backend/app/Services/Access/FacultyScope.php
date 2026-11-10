<?php

namespace App\Services\Access;

use App\Models\Batch;
use App\Models\Department;
use App\Models\Group;
use App\Models\User;

/**
 * FacultyScope — نطاق رؤية دور «قسم» (dept).
 * dept يرى/يقترح فقط ضمن كليته (users.faculty_id → departments → batches → groups).
 * admin / guard / batch: لا قيد (guard مركزي؛ batch غير مفعّل في هذه النسخة).
 */
class FacultyScope
{
    public static function userFacultyId(User $user): ?int
    {
        return $user->faculty_id !== null ? (int) $user->faculty_id : null;
    }

    /** @return int[]|null  null = بلا قيد (admin وغيره)، array = المعرفات المسموحة */
    public static function groupIdsFor(User $user): ?array
    {
        if ($user->role !== 'dept') {
            return null;
        }
        $facultyId = self::userFacultyId($user);
        if (! $facultyId) {
            return []; // dept بدون كلية مربوطة = لا يرى شيئًا
        }

        $departmentIds = Department::where('faculty_id', $facultyId)->pluck('id');
        $batchIds = Batch::whereIn('department_id', $departmentIds)->pluck('id');

        return Group::whereIn('batch_id', $batchIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** معرفات الشعب المسموح برؤيتها — يأخذها مراقب الاستعلامات */
    public static function groupQuery(User $user)
    {
        $ids = self::groupIdsFor($user);
        if ($ids === null) {
            return Group::query();
        }

        return Group::whereIn('groups.id', $ids);
    }

    public static function assertGroupInScope(User $user, int $groupId): bool
    {
        $ids = self::groupIdsFor($user);
        if ($ids === null) {
            return true;
        }

        return in_array($groupId, $ids, true);
    }

    /** رسالة عربية موحّدة عند تجاوز النطاق */
    public static function deniedMessage(): string
    {
        return 'هذه الشعبة خارج نطاق كليتك';
    }
}
