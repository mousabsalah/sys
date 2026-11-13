<?php

namespace App\Http\Controllers;

use App\Models\ExamSession;
use App\Models\Timetable;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

/**
 * AdminApprovalsController — «اعتماد الجداول والامتحانات» للمدير.
 * المجلس ينشئ → والجدول/الامتحان يبدأ pending ولا يراه إلا المجلس والمدير ←
 * المدير يعتمد من هنا → يظهر للبقية ويُحتسب في كل المسارات.
 */
class AdminApprovalsController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    // GET /api/admin/approvals — كل المعلّق جاهزًا بقراءة واضحة
    public function index()
    {
        $lectures = Timetable::pending()
            ->join('groups', 'groups.id', '=', 'timetables.group_id')
            ->leftJoin('courses', 'courses.id', '=', 'timetables.course_id')
            ->leftJoin('halls', 'halls.id', '=', 'timetables.hall_id')
            ->leftJoin('faculty', 'faculty.id', '=', 'timetables.faculty_id')
            ->leftJoin('batches', 'batches.id', '=', 'groups.batch_id')
            ->select(
                'timetables.id',
                'timetables.day_of_week',
                'timetables.start_time',
                'timetables.end_time',
                'timetables.academic_term',
                'groups.id AS group_id',
                'groups.track AS group_track',
                'batches.name AS batch_name',
                'courses.code AS course_code',
                'courses.name AS course_name',
                'halls.name AS hall_name',
                'faculty.full_name AS faculty_name',
            )
            ->orderBy('timetables.day_of_week')
            ->orderBy('timetables.start_time')
            ->get();

        $exams = ExamSession::pending()
            ->join('groups', 'groups.id', '=', 'exam_sessions.group_id')
            ->leftJoin('courses', 'courses.id', '=', 'exam_sessions.course_id')
            ->leftJoin('halls', 'halls.id', '=', 'exam_sessions.hall_id')
            ->leftJoin('batches', 'batches.id', '=', 'groups.batch_id')
            ->select(
                'exam_sessions.id',
                'exam_sessions.exam_date',
                'exam_sessions.start_time',
                'exam_sessions.end_time',
                'exam_sessions.exam_type',
                'exam_sessions.academic_term',
                'groups.id AS group_id',
                'groups.track AS group_track',
                'batches.name AS batch_name',
                'courses.code AS course_code',
                'courses.name AS course_name',
                'halls.name AS hall_name',
            )
            ->orderBy('exam_sessions.exam_date')
            ->get();

        return response()->json([
            'pending_lectures' => $lectures,
            'pending_exams' => $exams,
            'pending_lectures_count' => $lectures->count(),
            'pending_exams_count' => $exams->count(),
        ]);
    }

    // POST /api/admin/approvals/timetables/{timetable}/approve — اعتماد جدول
    public function approveTimetable(Request $request, int $timetable)
    {
        $item = Timetable::findOrFail($timetable);
        if ($item->status === 'approved') {
            return response()->json(['ok' => true, 'timetable' => $item, 'already' => true]);
        }

        $item->forceFill(['status' => 'approved'])->save();
        $this->audit->log($request, 'timetable.approve', 'timetable', $item->id, ['group_id' => $item->group_id]);

        return response()->json(['ok' => true, 'timetable' => $item->refresh()]);
    }

    // POST /api/admin/approvals/timetables/{timetable}/unapprove — إرجاع للانتظار
    public function unapproveTimetable(Request $request, int $timetable)
    {
        $item = Timetable::findOrFail($timetable);
        if ($item->status !== 'approved') {
            return response()->json(['ok' => true, 'timetable' => $item, 'already' => true]);
        }

        $item->forceFill(['status' => 'pending'])->save();
        $this->audit->log($request, 'timetable.unapprove', 'timetable', $item->id, ['group_id' => $item->group_id]);

        return response()->json(['ok' => true, 'timetable' => $item->refresh()]);
    }

    // POST /api/admin/approvals/exams/{exam}/approve — اعتماد امتحان
    public function approveExam(Request $request, int $exam)
    {
        $item = ExamSession::findOrFail($exam);
        if ($item->status === 'approved') {
            return response()->json(['ok' => true, 'exam' => $item, 'already' => true]);
        }

        $item->forceFill(['status' => 'approved'])->save();
        $this->audit->log($request, 'exam.approve', 'exam_session', $item->id, ['group_id' => $item->group_id]);

        return response()->json(['ok' => true, 'exam' => $item->refresh()]);
    }

    // POST /api/admin/approvals/exams/{exam}/unapprove — إرجاع للانتظار
    public function unapproveExam(Request $request, int $exam)
    {
        $item = ExamSession::findOrFail($exam);
        if ($item->status !== 'approved') {
            return response()->json(['ok' => true, 'exam' => $item, 'already' => true]);
        }

        $item->forceFill(['status' => 'pending'])->save();
        $this->audit->log($request, 'exam.unapprove', 'exam_session', $item->id, ['group_id' => $item->group_id]);

        return response()->json(['ok' => true, 'exam' => $item->refresh()]);
    }
}
