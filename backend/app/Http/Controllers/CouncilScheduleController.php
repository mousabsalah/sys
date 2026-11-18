<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Timetable;
use App\Services\Academic\ArabicDay;
use App\Support\Defaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CouncilScheduleController — «الجداول الدراسية» للمجلس.
 * المصدر الوحيد للمحاضرات هو «اقتراح تلقائي» ← «اعتماد الكل»؛ هذه الشاشة هي
 * نافذة العرض الجماعي للجدول المقترح/المعتمد، مع «أرشفة الجدول» (إخراج من كل
 * المسارات النشطة عبر status='archived') و«حذف الجدول كاملًا» للشعبة في فصل دراسي.
 */
class CouncilScheduleController extends Controller
{
    /**
     * GET /api/council/schedules?term=2026/1
     * كل الشعبة (مع تخصصها: قسم/دفعة/برنامج) + عدّادات حسب الحالة + كل صفوف الفصل المطلوب.
     */
    public function index(Request $request): JsonResponse
    {
        $term = (string) $request->query('term', Defaults::DEFAULT_TERM);

        $groups = DB::table('groups')
            ->join('batches', 'batches.id', '=', 'groups.batch_id')
            ->leftJoin('programs', 'programs.id', '=', 'batches.program_id')
            ->leftJoin('departments', 'departments.id', '=', 'batches.department_id')
            ->whereNull('batches.deleted_at')
            ->orderBy('departments.name')
            ->orderBy('batches.name')
            ->orderBy('groups.track')
            ->orderBy('groups.id')
            ->select(
                'groups.id',
                'groups.track',
                'groups.student_count',
                'batches.id AS batch_id',
                'batches.name AS batch_name',
                'programs.name AS program_name',
                'departments.name AS department_name',
                'departments.id AS department_id',
            )
            ->get();

        // عدّادات لكل شعبة/حالة
        $counts = [];
        $rawCounts = DB::table('timetables')
            ->select('timetables.group_id', 'timetables.status', DB::raw('count(*) AS total'))
            ->where('timetables.academic_term', $term)
            ->groupBy('timetables.group_id', 'timetables.status')
            ->get();
        foreach ($rawCounts as $raw) {
            $counts[(int) $raw->group_id][(string) $raw->status] = (int) $raw->total;
        }

        $groupsPayload = [];
        foreach ($groups as $g) {
            $gid = (int) $g->id;
            $byStatus = $counts[$gid] ?? [];
            $groupsPayload[] = [
                'id' => $gid,
                'batch' => (string) $g->batch_name,
                'track' => (string) $g->track,
                'student_count' => (int) $g->student_count,
                'program' => (string) ($g->program_name ?? ''),
                'department' => (string) ($g->department_name ?? ''),
                'department_id' => $g->department_id !== null ? (int) $g->department_id : null,
                'counts' => [
                    'total' => array_sum($byStatus),
                    'approved' => $byStatus['approved'] ?? 0,
                    'pending' => $byStatus['pending'] ?? 0,
                    'cancelled' => $byStatus['cancelled'] ?? 0,
                    'archived' => $byStatus['archived'] ?? 0,
                ],
            ];
        }

        $rows = DB::table('timetables')
            ->join('courses', 'courses.id', '=', 'timetables.course_id')
            ->leftJoin('halls', 'halls.id', '=', 'timetables.hall_id')
            ->leftJoin('faculty', 'faculty.id', '=', 'timetables.faculty_id')
            ->where('timetables.academic_term', $term)
            ->select(
                'timetables.id',
                'timetables.group_id',
                'timetables.status',
                'timetables.day_of_week',
                'timetables.start_time',
                'timetables.end_time',
                'courses.code AS course_code',
                'courses.name AS course_name',
                'halls.name AS hall_name',
                'faculty.full_name AS faculty_name',
            )
            ->orderByRaw(ArabicDay::dayOrderSql('timetables.day_of_week'))
            ->orderBy('timetables.start_time')
            ->get();

        $rowsPayload = [];
        foreach ($rows as $r) {
            $rowsPayload[] = [
                'id' => (int) $r->id,
                'group_id' => (int) $r->group_id,
                'status' => (string) $r->status,
                'day_of_week' => (string) $r->day_of_week,
                'start_time' => substr((string) $r->start_time, 0, 8),
                'end_time' => substr((string) $r->end_time, 0, 8),
                'course_code' => (string) $r->course_code,
                'course_name' => (string) $r->course_name,
                'hall_name' => $r->hall_name !== null ? (string) $r->hall_name : null,
                'faculty_name' => $r->faculty_name !== null ? (string) $r->faculty_name : null,
            ];
        }

        return response()->json([
            'academic_term' => $term,
            'groups' => $groupsPayload,
            'rows' => $rowsPayload,
        ]);
    }

    /** POST /api/council/schedules/archive — أرشفة جدول الشعبة في الفصل (كل ما هو نشط → archived) */
    public function archive(Request $request): JsonResponse
    {
        $input = $request->validate([
            'group_id' => ['required', 'integer', Rule::exists('groups', 'id')],
            'academic_term' => ['sometimes', 'string', 'max:20'],
        ]);
        $term = (string) ($input['academic_term'] ?? Defaults::DEFAULT_TERM);

        $archived = Timetable::where('group_id', (int) $input['group_id'])
            ->where('academic_term', $term)
            ->where('status', '!=', 'archived')
            ->update(['status' => 'archived', 'updated_at' => now()]);

        return response()->json(['ok' => true, 'archived' => (int) $archived]);
    }

    /** POST /api/council/schedules/unarchive — إلغاء الأرشفة: تعود pending وتحتاج اعتماد المدير من جديد */
    public function unarchive(Request $request): JsonResponse
    {
        $input = $request->validate([
            'group_id' => ['required', 'integer', Rule::exists('groups', 'id')],
            'academic_term' => ['sometimes', 'string', 'max:20'],
        ]);
        $term = (string) ($input['academic_term'] ?? Defaults::DEFAULT_TERM);

        $restored = Timetable::where('group_id', (int) $input['group_id'])
            ->where('academic_term', $term)
            ->where('status', 'archived')
            ->update(['status' => 'pending', 'updated_at' => now()]);

        return response()->json(['ok' => true, 'restored' => (int) $restored]);
    }

    /**
     * DELETE /api/council/schedules/{group}?academic_term=2026/1
     * حذف الجدول كاملًا (كل الحالات: pending/approved/cancelled/archived) بمعاملة واحدة.
     * يرفض بـ 409 إذا كانت أي محاضرة الشعبة مرتبطة بطلب بدلاء (الحذف كان سيمحيه ضمنيًا بالـ CASCADE).
     */
    public function destroy(Request $request, int $group): JsonResponse
    {
        $input = $request->validate([
            'academic_term' => ['sometimes', 'string', 'max:20'],
        ]);
        $term = (string) ($input['academic_term'] ?? Defaults::DEFAULT_TERM);
        $groupModel = Group::findOrFail($group);

        $affectedIds = Timetable::where('group_id', (int) $groupModel->id)
            ->where('academic_term', $term)
            ->pluck('id');

        if ($affectedIds->isEmpty()) {
            return response()->json(['ok' => true, 'deleted' => 0]);
        }

        $substitutes = DB::table('substitute_requests')
            ->whereIn('timetable_id', $affectedIds)
            ->count();
        if ($substitutes > 0) {
            return response()->json([
                'ok' => false,
                'message_ar' => "لا يمكن حذف جدول الشعبة — توجد {$substitutes} طلب بدلاء مرتبط بمحاضراتها. عالجها من شاشة «طلبات البدلاء» أولًا.",
                'substitute_requests' => (int) $substitutes,
            ], 409);
        }

        $deleted = 0;
        DB::transaction(function () use (&$deleted, $affectedIds): void {
            $deleted = Timetable::whereIn('id', $affectedIds)->delete();
        });

        return response()->json(['ok' => true, 'deleted' => (int) $deleted]);
    }
}
