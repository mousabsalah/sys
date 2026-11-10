<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Group;
use App\Models\GroupCourse;
use Illuminate\Database\Seeder;

/**
 * الخطة الدراسية (sample — البيانات الفعلية يدخلها المسؤول من الواجهة).
 * مسارات منطقية: دبلوم = مقررات تقنية مركزة؛ بكلاريوس = + مقررات متقدمة.
 * sessions_per_week = credit_hours للمقرر المعني.
 */
class GroupCoursesSeeder extends Seeder
{
    private const TRACK_PLANS = [
        'دبلوم' => ['MATH101', 'MATH201', 'CS101', 'CS201', 'CS202', 'CS203', 'CE101', 'EEE101'],
        'بكلاريوس' => ['MATH101', 'MATH201', 'CS101', 'CS201', 'CS202', 'CS302', 'CS401', 'ECON201'],
    ];

    public function run(): void
    {
        $coursesByCode = Course::pluck('credit_hours', 'code')->all();

        foreach (Group::all() as $group) {
            $plan = self::TRACK_PLANS[$group->track] ?? self::TRACK_PLANS['دبلوم'];
            foreach ($plan as $code) {
                $credit = (int) ($coursesByCode[$code] ?? 3);
                GroupCourse::updateOrCreate(
                    ['group_id' => $group->id, 'course_id' => Course::where('code', $code)->value('id')],
                    ['sessions_per_week' => max(1, min(6, $credit))],
                );
            }
        }
    }
}
