<?php

namespace App\Services\Auth;

/**
 * TempPassword — المولّد المشترك لكلمات المرور المؤقتة (إعادة تعيين/إنشاء الحسابات عبر الإدارة والمجلس).
 * مصدر واحد للألفبائية ولطول الكلمة بدل ثلاث نسخ متباينة.
 */
final class TempPassword
{
    /** الأبجدية: خلطة أحرف/أرقام واضحة بلا غموض (لا توجد O/0/1/l/I) */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789@#$%!';

    public static function issue(int $length = 10): string
    {
        $password = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }

        return $password;
    }
}
