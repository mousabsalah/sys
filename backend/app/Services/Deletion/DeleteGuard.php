<?php

namespace App\Services\Deletion;

use Illuminate\Validation\ValidationException;

/**
 * DeleteGuard — فحص موحّد قبل الحذف: لا تحذف كيانًا مرتبطًا.
 * كل blockers تمر كمصفوفة [رسالة => مستخدم؟]؛ أول مستخدم يوقف الحذف بنفس رسالة الواجهة.
 */
final class DeleteGuard
{
    /**
     * @param  string  $key  مفتاح رسالة التحقق (errors.<key>)
     * @param  array<string, bool>  $blockers  ['لا يمكن حذف ...' => bool]
     */
    public static function assertUnused(string $key, array $blockers): void
    {
        foreach ($blockers as $message => $isUsed) {
            if ($isUsed) {
                throw ValidationException::withMessages([$key => [$message]]);
            }
        }
    }
}
