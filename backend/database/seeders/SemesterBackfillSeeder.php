<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Department;
use App\Models\Semester;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SemesterBackfillSeeder — توليد سطر semester واحد لكل دفعة (8 دفعات) ونسب الحجوزات والمحاضرات والامتحانات الحالية إليه.
 * يُشغَّل مرة واحدة بعد create_semesters_table migration.
 */
class SemesterBackfillSeeder extends Seeder
{
    public function run(): void
    {
        // إنشاء سطر لكل دفعة ( batches لما 8 دفعات بالقيد السابق AcademicTerm='2026/1' )
        foreach (Batch::all() as $batch) {
            $department = Department::findOrFail($batch->department_id);
            $facultyId = (int) $department->faculty_id;

            Semester::firstOrCreate(
                ['department_id' => $batch->department_id, 'batch_id' => $batch->id, 'academic_term' => '2026/1'],
                ['faculty_id' => $facultyId, 'start_date' => '2026-02-01', 'end_date' => '2026-06-30'],
            );
        }

        // ربط الجداول الحالية بسم ester لكل دفعة
        DB::table('timetables')
            ->select('group_id')
            ->distinct()
            ->orderBy('group_id')
            ->get()
            ->each(function ($row) {
                $group = DB::table('groups')->where('id', $row->group_id)->first();
                if (! $group) {
                    return;
                }
                $semester = DB::table('semesters')
                    ->where('batch_id', $group->batch_id)
                    ->where('academic_term', '2026/1')
                    ->first();
                if ($semester) {
                    DB::table('timetables')
                        ->where('group_id', $row->group_id)
                        ->whereNull('semester_id')
                        ->update(['semester_id' => $semester->id]);
                }
            });

        // ربط الامتحانات الحالية
        DB::table('exam_sessions')
            ->join('groups', 'groups.id', '=', 'exam_sessions.group_id')
            ->leftJoin('semesters', function ($join) {
                $join->on('semesters.batch_id', '=', 'groups.batch_id')
                    ->where('semesters.academic_term', '2026/1');
            })
            ->select('exam_sessions.id', 'semesters.id as semester_id')
            ->orderBy('exam_sessions.id')
            ->get()
            ->each(function ($row) {
                if ($row->semester_id) {
                    DB::table('exam_sessions')
                        ->where('id', $row->id)
                        ->update(['semester_id' => $row->semester_id]);
                }
            });

        // ربط الحجوزات الحالية بسم ester اخذ
        DB::table('bookings')
            ->leftJoin('courses', 'courses.id', '=', 'bookings.course_id')
            ->get()
            ->each(function ($row) {
                $semester = DB::table('semesters')
                    ->where('academic_term', '2026/1')
                    ->first();
                if ($semester) {
                    DB::table('bookings')
                        ->where('id', $row->id)
                        ->whereNull('semester_id')
                        ->update(['semester_id' => $semester->id]);
                }
            });
    }
}
