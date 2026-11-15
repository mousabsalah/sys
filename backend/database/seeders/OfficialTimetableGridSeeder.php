<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * OfficialTimetableGridSeeder — شبكة الفصول الأسبوعية للجامعة (٦ أيام × ٣ فترات)
 * مطابقة تمامًا للـ«ورقة الرسمية» (السبت–الخميس / 7–9 ← 10–12 ← 12–2).
 * يُنشأ تلقائيًا إن لم توجد صفوف في جدول periods — أعد التشغيل آمنة.
 */
class OfficialTimetableGridSeeder extends Seeder
{
    public function run(): void
    {
        $days = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس'];
        $slots = [
            ['label' => 'الفترة الأولى', 'start' => '07:00:00', 'end' => '09:00:00'],
            ['label' => 'الفترة الثانية', 'start' => '10:00:00', 'end' => '12:00:00'],
            ['label' => 'الفترة الثالثة', 'start' => '12:00:00', 'end' => '14:00:00'],
        ];

        $programs = DB::table('programs')->pluck('id');
        $now = now();
        foreach ($programs as $programId) {
            foreach ($days as $dayOrder => $day) {
                foreach ($slots as $i => $slot) {
                    $exists = DB::table('periods')
                        ->where('program_id', $programId)
                        ->where('day_of_week', $day)
                        ->where('start_time', $slot['start'])
                        ->exists();
                    if ($exists) {
                        continue;
                    }
                    DB::table('periods')->insert([
                        'program_id' => $programId,
                        'day_of_week' => $day,
                        'start_time' => $slot['start'],
                        'end_time' => $slot['end'],
                        'label' => $slot['label'],
                        'sort_order' => ($dayOrder * 10) + $i + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }
}
