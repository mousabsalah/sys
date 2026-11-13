<?php

namespace App\Services\Access;

use App\Models\Group;
use App\Models\User;

/**
 * FacultyScope — كان نطاق رؤية دور «قسم» (dept).
 * أُلغي دور dept واستُبدل بـ council («مجلس المدرسين») بنطاق الجامعة كلها.
 * أُبقيت الأدوات بنفس التواقيع (توافق الكود القديم) لكن بلا قيد نطاق — كل الأدوار ترى الكل.
 */
class FacultyScope
{
    public static function userFacultyId(User $user): ?int
    {
        return $user->faculty_id !== null ? (int) $user->faculty_id : null;
    }

    /** @return null دائمًا — لا قيد نطاق بعد إلغاء dept */
    public static function groupIdsFor(User $user): ?array
    {
        return null;
    }

    public static function groupQuery(User $user)
    {
        return Group::query();
    }

    public static function assertGroupInScope(User $user, int $groupId): bool
    {
        return true;
    }

    public static function deniedMessage(): string
    {
        return 'هذه الشعبة خارج النطاق';
    }
}
