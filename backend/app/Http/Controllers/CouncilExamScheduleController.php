<?php

namespace App\Http\Controllers;

use App\Models\ExamSession;
use App\Models\Group;
use App\Services\Academic\ArabicDay;
use App\Services\Academic\SpecializationGroups;
use App\Support\Defaults;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CouncilExamScheduleController — «جدول الامتحانات» للمجلس (مرآة «الجداول الدراسية»).
 * لا توجد شاشة لإنشاء الامتحانات يدويًا في النظام — المسار هو «اقتراح الامتحانات التلقائي»؛
 * هذه الشاشة هي نافذة العرض الجماعي للفاينلات المقترحة/المعتمدة، مع «أرشفة» يدوية و«حذف» ذهائيًا للشعبة في فصل.
 */
class CouncilExamScheduleController extends Controller
{
    /**
     * GET /api/council/exam-schedules?term=2026/1
     * كل الشعبة (مع تخصصها: قسم/دفعة/برنامج) + عدّادات حسب الحالة + كل امتحانات الفصل.
     */
    public function index(Request $request): JsonResponse
    {
        $term = (string) $request->query('term', Defaults::DEFAULT_TERM);

        $groups = SpecializationGroups::list();

        $counts = [];
        $rawCounts = DB::table('exam_sessions')
            ->select('exam_sessions.group_id', 'exam_sessions.status', DB::raw('count(*) AS total'))
            ->where('exam_sessions.academic_term', $term)
            ->groupBy('exam_sessions.group_id', 'exam_sessions.status')
            ->get();
        foreach ($rawCounts as $raw) {
            $counts[(int) $raw->group_id][(string) $raw->status] = (int) $raw->total;
        }

        $groupsPayload = [];
        foreach ($groups as $g) {
            $gid = (int) $g['id'];
            $byStatus = $counts[$gid] ?? [];
            $groupsPayload[] = [
                'id' => $gid,
                'batch' => (string) $g['batch'],
                'track' => (string) $g['track'],
                'student_count' => (int) $g['student_count'],
                'program' => (string) $g['program'],
                'department' => (string) $g['department'],
                'department_id' => $g['department_id'],
                'counts' => [
                    'total' => array_sum($byStatus),
                    'approved' => $byStatus['approved'] ?? 0,
                    'pending' => $byStatus['pending'] ?? 0,
                    'cancelled' => $byStatus['cancelled'] ?? 0,
                    'archived' => $byStatus['archived'] ?? 0,
                ],
            ];
        }

        $rows = DB::table('exam_sessions')
            ->join('courses', 'courses.id', '=', 'exam_sessions.course_id')
            ->leftJoin('halls', 'halls.id', '=', 'exam_sessions.hall_id')
            ->where('exam_sessions.academic_term', $term)
            ->select(
                'exam_sessions.id',
                'exam_sessions.group_id',
                'exam_sessions.status',
                'exam_sessions.exam_date',
                'exam_sessions.exam_type',
                'exam_sessions.start_time',
                'exam_sessions.end_time',
                'courses.code AS course_code',
                'courses.name AS course_name',
                'halls.name AS hall_name',
            )
            ->orderBy('exam_sessions.exam_date')
            ->orderBy('exam_sessions.start_time')
            ->get();

        $rowsPayload = [];
        foreach ($rows as $r) {
            $rowsPayload[] = [
                'id' => (int) $r->id,
                'group_id' => (int) $r->group_id,
                'status' => (string) $r->status,
                'exam_date' => (string) $r->exam_date,
                'day' => ArabicDay::of(Carbon::parse((string) $r->exam_date)),
                'exam_type' => (string) $r->exam_type,
                'start_time' => substr((string) $r->start_time, 0, 8),
                'end_time' => substr((string) $r->end_time, 0, 8),
                'course_code' => (string) $r->course_code,
                'course_name' => (string) $r->course_name,
                'hall_name' => $r->hall_name !== null ? (string) $r->hall_name : null,
            ];
        }

        return response()->json([
            'academic_term' => $term,
            'groups' => $groupsPayload,
            'rows' => $rowsPayload,
        ]);
    }

    /** POST /api/council/exam-schedules/archive — أرشفة جدول امتحانات الشعبة في الفصل (نشط → archived) */
    public function archive(Request $request): JsonResponse
    {
        $input = $request->validate([
            'group_id' => ['required', 'integer', Rule::exists('groups', 'id')],
            'academic_term' => ['sometimes', 'string', 'max:20'],
        ]);
        $term = (string) ($input['academic_term'] ?? Defaults::DEFAULT_TERM);

        $archived = ExamSession::where('group_id', (int) $input['group_id'])
            ->where('academic_term', $term)
            ->where('status', '!=', 'archived')
            ->update(['status' => 'archived', 'updated_at' => now()]);

        return response()->json(['ok' => true, 'archived' => (int) $archived]);
    }

    /** POST /api/council/exam-schedules/unarchive — إلغاء الأرشفة: تعود pending وتحتاج اعتماد المدير من جديد */
    public function unarchive(Request $request): JsonResponse
    {
        $input = $request->validate([
            'group_id' => ['required', 'integer', Rule::exists('groups', 'id')],
            'academic_term' => ['sometimes', 'string', 'max:20'],
        ]);
        $term = (string) ($input['academic_term'] ?? Defaults::DEFAULT_TERM);

        $restored = ExamSession::where('group_id', (int) $input['group_id'])
            ->where('academic_term', $term)
            ->where('status', 'archived')
            ->update(['status' => 'pending', 'updated_at' => now()]);

        return response()->json(['ok' => true, 'restored' => (int) $restored]);
    }

    /**
     * DELETE /api/council/exam-schedules/{group}?academic_term=2026/1
     * حذف جدول الامتحانات كاملًا بمعاملة وحدة (كل الحالات).
     */
    public function destroy(Request $request, int $group): JsonResponse
    {
        $input = $request->validate([
            'academic_term' => ['sometimes', 'string', 'max:20'],
        ]);
        $term = (string) ($input['academic_term'] ?? Defaults::DEFAULT_TERM);
        $groupModel = Group::findOrFail($group);

        $deleted = 0;
        DB::transaction(function () use (&$deleted, $groupModel, $term): void {
            $deleted = ExamSession::where('group_id', (int) $groupModel->id)
                ->where('academic_term', $term)
                ->delete();
        });

        return response()->json(['ok' => true, 'deleted' => (int) $deleted]);
    }
}
