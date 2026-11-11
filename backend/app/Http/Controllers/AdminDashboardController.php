<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\ExamSession;
use App\Models\Semester;
use App\Models\Timetable;
use Illuminate\Support\Facades\DB;

/**
 * AdminDashboardController — بيانات رسومية جاهزة للرسم البياني في لوحة الأدمن + قراءة الأرشفة.
 * يحترم منطق الأرشفة: المحاضرات/الامتحانات/الحجوزات المرتبطة بفصل مؤرشف تُستبعد من كل الإحصاءات.
 */
class AdminDashboardController extends Controller
{
    public function dashboardStats()
    {
        // ── القاعات مع إشغالها (محاضرات/امتحانات/حجوزات غير مؤرشفة) ──
        $lectures = Timetable::notArchived()
            ->select('hall_id', DB::raw('COUNT(*) AS lectures'))
            ->groupBy('hall_id')
            ->pluck('lectures', 'hall_id');
        $exams = ExamSession::notArchived()
            ->select('hall_id', DB::raw('COUNT(*) AS exams'))
            ->groupBy('hall_id')
            ->pluck('exams', 'hall_id');
        $bookings = Booking::active()
            ->select('hall_id', DB::raw('COUNT(*) AS bookings'))
            ->groupBy('hall_id')
            ->pluck('bookings', 'hall_id');

        $halls = DB::table('halls')
            ->whereNull('halls.deleted_at')
            ->select('halls.id', 'halls.name', 'halls.capacity', 'halls.hall_type', 'halls.status')
            ->orderByDesc('halls.capacity')
            ->get()
            ->map(fn ($h) => [
                'id' => (int) $h->id,
                'name' => (string) $h->name,
                'capacity' => (int) $h->capacity,
                'hall_type' => (string) $h->hall_type,
                'status' => (string) $h->status,
                'lectures' => (int) ($lectures[$h->id] ?? 0),
                'exams' => (int) ($exams[$h->id] ?? 0),
                'bookings' => (int) ($bookings[$h->id] ?? 0),
            ])
            ->sortByDesc('lectures')
            ->values();

        // ── توزيع المحاضرات حسب يوم الأسبوع (مخزنة كنص عربي) ──
        $dayOrder = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس'];
        $rawDays = Timetable::notArchived()
            ->select('day_of_week', DB::raw('COUNT(*) AS lectures'))
            ->groupBy('day_of_week')
            ->pluck('lectures', 'day_of_week');
        $dayDistribution = [];
        foreach ($dayOrder as $day) {
            $dayDistribution[] = ['day' => $day, 'lectures' => (int) ($rawDays[$day] ?? 0)];
        }

        // ── حصة كل كلية من المحاضرات (عبر department → faculty) ──
        $facultyShare = Timetable::notArchived()
            ->join('groups', 'groups.id', '=', 'timetables.group_id')
            ->join('batches', 'batches.id', '=', 'groups.batch_id')
            ->join('departments', 'departments.id', '=', 'batches.department_id')
            ->join('faculties', 'faculties.id', '=', 'departments.faculty_id')
            ->select('faculties.id', 'faculties.name', DB::raw('COUNT(timetables.id) AS lectures'))
            ->groupBy('faculties.id', 'faculties.name')
            ->orderByDesc('lectures')
            ->get();

        // ── نشاط كل فصل دراسي (للرسم الخطي عبر الزمن) — بإحصاءات غير مؤرشفة فقط ──
        $semesterActivity = Semester::withCount([
            'timetables' => fn ($q) => $q->notArchived(),
            'examSessions' => fn ($q) => $q->notArchived(),
            'bookings' => fn ($q) => $q->active(),
        ])
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
            'halls_count' => DB::table('halls')->whereNull('deleted_at')->count(),
            'halls_available_now' => DB::table('halls')->whereNull('deleted_at')->where('status', 'متاحة')->count(),
            'total_students' => (int) DB::table('batches')->sum('student_count'),
            'faculty_members' => DB::table('faculty')->count(),
            'lectures_total' => Timetable::notArchived()->count(),
            'exams_total' => ExamSession::notArchived()->count(),
            'bookings_total' => Booking::active()->count(),
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
            ->orderByRaw("CASE timetables.day_of_week WHEN 'السبت' THEN 1 WHEN 'الأحد' THEN 2 WHEN 'الاثنين' THEN 3 WHEN 'الثلاثاء' THEN 4 WHEN 'الأربعاء' THEN 5 WHEN 'الخميس' THEN 6 WHEN 'الجمعة' THEN 7 ELSE 99 END, timetables.start_time")
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
