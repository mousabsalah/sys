<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\ExamSession;
use App\Models\Group;
use App\Models\Timetable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BatchController — دور «دفعة»: كل شيء عن دفعته هو فقط — ربعي البيانات لا تخرج أبدًا من users.batch_id.
 */
class BatchController extends Controller
{
    /** user ← batch_id؛ 403 عند غيابه */
    private function batchId(Request $request): int
    {
        $id = $request->user()?->batch_id;
        if (! $id) {
            abort(403, 'هذا الحساب غير مرتبط بدفعة — تواصل مع مجلس المدرسين.');
        }

        return (int) $id;
    }

    private function ownGroupIds(int $batchId): array
    {
        return Group::where('batch_id', $batchId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // GET /api/batch/timetables — جدول شعبه كلها (صفوف دفعته المعتمدة + الملغية الموسومة)
    public function timetables(Request $request)
    {
        $batchId = $this->batchId($request);
        $groupIds = $this->ownGroupIds($batchId);
        $batch = Batch::find($batchId);

        $rows = Timetable::notArchived()
            ->whereIn('timetables.group_id', $groupIds)
            ->where('timetables.status', '!=', 'pending') // المعلّقة لا تراها الدفعة أبدًا
            ->leftJoin('courses', 'courses.id', '=', 'timetables.course_id')
            ->leftJoin('groups', 'groups.id', '=', 'timetables.group_id')
            ->leftJoin('halls', 'halls.id', '=', 'timetables.hall_id')
            ->leftJoin('faculty', 'faculty.id', '=', 'timetables.faculty_id')
            ->select(
                'timetables.id',
                'timetables.group_id',
                'timetables.day_of_week',
                'timetables.start_time',
                'timetables.end_time',
                'timetables.academic_term',
                'timetables.status',
                'courses.code AS course_code',
                'courses.name AS course_name',
                'groups.track AS track',
                'halls.name AS hall_name',
                'faculty.full_name AS faculty_name',
            )
            ->orderByRaw("CASE timetables.day_of_week WHEN 'السبت' THEN 1 WHEN 'الأحد' THEN 2 WHEN 'الاثنين' THEN 3 WHEN 'الثلاثاء' THEN 4 WHEN 'الأربعاء' THEN 5 WHEN 'الخميس' THEN 6 ELSE 99 END, timetables.start_time")
            ->get();

        return response()->json([
            'batch' => $batch ? ['id' => $batch->id, 'name' => $batch->name] : ['id' => $batchId, 'name' => 'دفعتي'],
            'groups' => $this->groupsPayload($batchId),
            'rows' => $rows,
        ]);
    }

    // GET /api/batch/exams — جدول امتحانات دفعته (المعتمدة + الملغية الموسومة)
    public function exams(Request $request)
    {
        $batchId = $this->batchId($request);
        $groupIds = $this->ownGroupIds($batchId);
        $batch = Batch::find($batchId);

        $rows = ExamSession::notArchived()
            ->whereIn('exam_sessions.group_id', $groupIds)
            ->where('exam_sessions.status', '!=', 'pending')
            ->leftJoin('courses', 'courses.id', '=', 'exam_sessions.course_id')
            ->leftJoin('groups', 'groups.id', '=', 'exam_sessions.group_id')
            ->leftJoin('halls', 'halls.id', '=', 'exam_sessions.hall_id')
            ->select(
                'exam_sessions.id',
                'exam_sessions.group_id',
                'exam_sessions.exam_date',
                'exam_sessions.start_time',
                'exam_sessions.end_time',
                'exam_sessions.exam_type',
                'exam_sessions.academic_term',
                'exam_sessions.status',
                'courses.code AS course_code',
                'courses.name AS course_name',
                'groups.track AS track',
                'halls.name AS hall_name',
            )
            ->orderBy('exam_sessions.exam_date')
            ->orderBy('exam_sessions.start_time')
            ->get();

        return response()->json([
            'batch' => $batch ? ['id' => $batch->id, 'name' => $batch->name] : ['id' => $batchId, 'name' => 'دفعتي'],
            'rows' => $rows,
        ]);
    }

    private function groupsPayload(int $batchId): array
    {
        return Group::where('groups.batch_id', $batchId)
            ->join('batches', 'batches.id', '=', 'groups.batch_id')
            ->orderBy('groups.id')
            ->get(['groups.id', 'batches.name as batch', 'groups.track', 'groups.student_count'])
            ->toArray();
    }

    // GET /api/batch/notifications — آخر تغييرات محاضرات/امتحانات دفعته (إلغاء/إرجاع/إضافة)
    public function notifications(Request $request)
    {
        $batchId = $this->batchId($request);
        $groupIds = $this->ownGroupIds($batchId);

        $rows = DB::table('timetable_updates as tu')
            ->join('groups', 'groups.id', '=', 'tu.group_id')
            ->leftJoin('timetables', 'timetables.id', '=', 'tu.timetable_id')
            ->leftJoin('courses', 'courses.id', '=', 'timetables.course_id')
            ->whereIn('tu.group_id', $groupIds)
            ->orderByDesc('tu.id')
            ->limit(30)
            ->get([
                'tu.id',
                'tu.group_id',
                'tu.timetable_id',
                'tu.update_type',
                'tu.description',
                'tu.created_at',
                'groups.track AS track',
                'courses.code AS course_code',
                'courses.name AS course_name',
                'timetables.day_of_week',
                'timetables.start_time',
                'timetables.end_time',
                'timetables.status AS timetable_status',
            ]);

        $nextCursor = $rows->max('id') ?? 0;

        return response()->json(['updates' => $rows, 'latest_id' => $nextCursor]);
    }

    // GET /api/batch/notifications/count — عدد غير المقروءة (لحقة الجرس)
    public function notificationsCount(Request $request)
    {
        $batchId = $this->batchId($request);
        $groupIds = $this->ownGroupIds($batchId);
        $seenAt = $request->user()?->notifications_seen_at;

        $query = DB::table('timetable_updates as tu')->whereIn('tu.group_id', $groupIds);
        if ($seenAt) {
            $query->where('tu.created_at', '>', $seenAt);
        }

        return response()->json(['unread' => (int) $query->count()]);
    }

    // POST /api/batch/notifications/read — تحديد الكل كمقروء
    public function markNotificationsRead(Request $request)
    {
        $request->user()?->forceFill(['notifications_seen_at' => now()])->save();

        return response()->json(['ok' => true]);
    }
}
