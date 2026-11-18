<?php

namespace App\Services\Academic;

use Carbon\Carbon;

/**
 * ExamSlots — توليد فترات الامتحانات الفعلية داخل مدى يدوي.
 * كل يوم في المدى (ما عدا الجمعة) يحمل محاولتين: صباح 09:00–12:00 / مساء 12:30–15:30 — 3 ساعات للفترة.
 */
final class ExamSlots
{
    /**
     * @return array<int, array{slot_id:int, date:string, day:string, start_time:string, end_time:string, label:string}>
     */
    public static function build(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        $slots = [];
        $id = 1;
        for ($cursor = $start->copy(); $cursor <= $end; $cursor->addDay()) {
            if ($cursor->isFriday()) {
                continue; // الجمعة عطلة رسمية — لا امتحانات
            }
            $date = $cursor->toDateString();
            $day = ArabicDay::of($cursor);
            $slots[] = ['slot_id' => $id++, 'date' => $date, 'day' => $day, 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'label' => 'صباحًا'];
            $slots[] = ['slot_id' => $id++, 'date' => $date, 'day' => $day, 'start_time' => '12:30:00', 'end_time' => '15:30:00', 'label' => 'مساءً'];
        }

        return $slots;
    }
}
