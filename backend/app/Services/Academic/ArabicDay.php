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

    public static function of(Carbon|string $date): string
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);

        return self::MAP[$d->dayOfWeek];
    }
}
