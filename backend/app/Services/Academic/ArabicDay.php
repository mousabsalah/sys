<?php

namespace App\Services\Academic;

use Carbon\Carbon;

/**
 * ArabicDay — اليوم الأسبوعي بالعربية كما يُخزَّن في الجداول/الامتحانات/الحجوزات.
 * مصدر واحد للحقيقة بدل نسخ الخريطة في كل متحكم.
 */
final class ArabicDay
{
    /** @var array<int, string> */
    public const MAP = [
        Carbon::SUNDAY => 'الأحد',
        Carbon::MONDAY => 'الاثنين',
        Carbon::TUESDAY => 'الثلاثاء',
        Carbon::WEDNESDAY => 'الأربعاء',
        Carbon::THURSDAY => 'الخميس',
        Carbon::FRIDAY => 'الجمعة',
        Carbon::SATURDAY => 'السبت',
    ];

    /** ترتيب أيام الدراسة المعتمد بالجامعة (السبت أول) — نفس ترتيب الورقة الرسمية والاقتراح. */
    /** @var array<int, string> */
    public const ORDERED_STUDY_DAYS = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس'];

    public static function of(Carbon|string $date): string
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);

        return self::MAP[$d->dayOfWeek];
    }

    /**
     * CASE SQL للترتيب الأكاديمي للشُعب (دبلوم أولاً ثم بكالوريوس/بكلاريوس ثم البقية).
     *
     * @param  string  $column  اسم العمود (مملي بصيغة table.column)
     */
    public static function groupTrackOrderSql(string $column): string
    {
        return "CASE {$column} WHEN 'دبلوم' THEN 1 WHEN 'بكلاريوس' THEN 2 ELSE 3 END";
    }

    /**
     * قائمة أيام الدراسة بالترتيب الأكاديمي، كما تُحقن في قوائم الاختيارات.
     *
     * @return array<int, string>
     */
    public static function orderedStudyDays(): array
    {
        return self::ORDERED_STUDY_DAYS;
    }

    /**
     * CASE SQL جاهز للترتيب حسب ترتيب أيام الدراسة (السبت=1 … الخميس=6 وبقية القيم تحتل النهاية).
     *
     * @param  string  $column  اسم العمود (مملي بصيغة table.column مطابق للمحيط)
     */
    public static function dayOrderSql(string $column): string
    {
        $cases = [];
        foreach (self::ORDERED_STUDY_DAYS as $i => $day) {
            $cases[] = "WHEN '{$day}' THEN ".($i + 1);
        }

        return 'CASE '.$column.' '.implode(' ', $cases).' ELSE 99 END';
    }
}
