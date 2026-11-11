<?php

namespace App\Http\Controllers;

use App\Models\Semester;
use Illuminate\Support\Facades\DB;

/**
 * AdminDashboardController — بيانات رسومية جاهزة للرسم البياني في لوحة الأدمن + قراءة الأرشفة.
 * يجمع كل ما تحتاجه الواجهة في طلب واحد لتقليل الجوالة بين الشاشة والسيرفر.
 */
class AdminDashboardController extends Controller
{
    public function dashboardStats()
    {
        // ── القاعات مع إشغالها (عدد المحاضرات + عدد الامتحانات + الحجوزات) ──
        $halls = DB::table('halls')
            ->leftJoin('timetables', 'timetables.hall_id', '=', 'halls.id')
            ->leftJoin('exam_sessions', 'exam_sessions.hall_id', '=', 'halls.id')
            ->leftJoin('bookings', 'bookings.hall_id', '=', 'halls.id')
            ->select(
                'halls.id',
                'halls.name',
                'halls.capacity',
                'halls.hall_type',
                'halls.status',
                DB::raw('COUNT(DISTINCT timetables.id) AS lectures'),
                DB::raw('COUNT(DISTINCT exam_sessions.id) AS exams'),
                DB::raw('COUNT(DISTINCT bookings.id) AS bookings'),
            )
            ->groupBy('halls.id', 'halls.name', 'halls.capacity', 'halls.hall_type', 'halls.status')
            ->orderByDesc('lectures')
            ->get();

        // ── توزيع المحاضرات حسب يوم الأسبوع (مخزنة كنص عربي) ──
        $dayOrder = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس'];
        $rawDays = DB::table('timetables')
            ->select('day_of_week', DB::raw('COUNT(*) AS lectures'))
            ->groupBy('day_of_week')
            ->pluck('lectures', 'day_of_week');
        $dayDistribution = [];
        foreach ($dayOrder as $day) {
            $dayDistribution[] = ['day' => $day, 'lectures' => (int) ($rawDays[$day] ?? 0)];
        }

        // ── حصة كل كلية من المحاضرات (عبر department → faculty) ──
        $facultyShare = DB::table('timetables')
            ->join('groups', 'groups.id', '=', 'timetables.group_id')
            ->join('batches', 'batches.id', '=', 'groups.batch_id')
            ->join('departments', 'departments.id', '=', 'batches.department_id')
            ->join('faculties', 'faculties.id', '=', 'departments.faculty_id')
            ->select('faculties.id', 'faculties.name', DB::raw('COUNT(timetables.id) AS lectures'))
            ->groupBy('faculties.id', 'faculties.name')
            ->orderByDesc('lectures')
            ->get();

        // ── نشاط كل فصل دراسي (للرسم الخطي عبر الزمن) ──
        $semesterActivity = Semester::withCount(['timetables', 'examSessions', 'bookings'])
            ->with('faculty:id,name', 'department:id,name', 'batch:id,name')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'academic_term' => $s->academic_term,
                'faculty' => $s->faculty?->name,
                'department' => $s->department?->name,
                'batch' => $s->batch?->name,
                'start_date' => $s->start_date?->format('Y-m-d'),
                'end_date' => $s->end_date?->format('Y-m-d'),
                'archived_at' => $s->archived_at,
                'lectures' => (int) $s->timetables_count,
                'exams' => (int) $s->exam_sessions_count,
                'bookings' => (int) $s->bookings_count,
            ]);

        // ── مؤشرات إضافية ──
        $kpis = [
            'halls_count' => DB::table('halls')->count(),
            'halls_available_now' => DB::table('halls')->where('status', 'متاحة')->count(),
            'total_students' => (int) DB::table('batches')->sum('student_count'),
            'faculty_members' => DB::table('faculty')->count(),
            'lectures_total' => DB::table('timetables')->count(),
            'exams_total' => DB::table('exam_sessions')->count(),
            'bookings_total' => DB::table('bookings')->count(),
            'batches_count' => DB::table('batches')->count(),
            'courses_count' => DB::table('courses')->count(),
            'groups_count' => DB::table('groups')->count(),
        ];

        return response()->json([
            'kpis' => $kpis,
            'halls' => $halls,
            'day_distribution' => $dayDistribution,
            'faculty_share' => $facultyShare,
            'semester_activity' => $semesterActivity,
        ]);
    }

    /**
     * جداول فصل دراسي مؤرشف للعرض فقط (indexed بـ القسم/السنة/الدفعة عبر الواجهة).
     */
    public function archivedSchedule(int $semester)
    {
        $item = Semester::with('faculty:id,name', 'department:id,name', 'batch:id,name,student_count')->findOrFail($semester);

        if (! $item->archived_at) {
            return response()->json(['ok' => false, 'message_ar' => 'هذا الفصل غير مؤرشف بعد — يمكن مشاهدته من المسارات العادية.'], 422);
        }

        $timetables = DB::table('timetables')
            ->join('groups', 'groups.id', '=', 'timetables.group_id')
            ->leftJoin('courses', 'courses.id', '=', 'timetables.course_id')
            ->leftJoin('halls', 'halls.id', '=', 'timetables.hall_id')
            ->leftJoin('faculty', 'faculty.id', '=', 'timetables.faculty_id')
            ->where('timetables.semester_id', $item->id)
            ->select(
                'timetables.id',
                'groups.track AS group_track',
                'groups.id AS group_id',
                'courses.code AS course_code',
                'courses.name AS course_name',
                'timetables.day_of_week',
                'timetables.start_time',
                'timetables.end_time',
                'halls.name AS hall_name',
                'faculty.full_name AS faculty_name',
            )
            ->orderBy('timetables.day_of_week')
            ->orderBy('timetables.start_time')
            ->get();

        $exams = DB::table('exam_sessions')
            ->leftJoin('courses', 'courses.id', '=', 'exam_sessions.course_id')
            ->leftJoin('halls', 'halls.id', '=', 'exam_sessions.hall_id')
            ->leftJoin('groups', 'groups.id', '=', 'exam_sessions.group_id')
            ->where('exam_sessions.semester_id', $item->id)
            ->select(
                'exam_sessions.id',
                'courses.code AS course_code',
                'courses.name AS course_name',
                'exam_sessions.exam_date',
                'exam_sessions.start_time',
                'exam_sessions.end_time',
                'halls.name AS hall_name',
                'groups.id AS group_id',
                'groups.track AS group_track',
            )
            ->orderBy('exam_sessions.exam_date')
            ->get();

        return response()->json([
            'semester' => [
                'id' => $item->id,
                'academic_term' => $item->academic_term,
                'start_date' => $item->start_date?->format('Y-m-d'),
                'end_date' => $item->end_date?->format('Y-m-d'),
                'archived_at' => $item->archived_at,
                'faculty' => $item->faculty?->name,
                'department' => $item->department?->name,
                'batch' => $item->batch?->name,
                'student_count' => $item->batch?->student_count,
            ],
            'timetables' => $timetables,
            'exams' => $exams,
        ]);
    }
}
