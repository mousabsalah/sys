<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Course;
use App\Models\Group;
use App\Models\Timetable;
use App\Services\Academic\ArabicDay;
use App\Services\Engine\ProposeGroupSolver;
use App\Support\Defaults;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ProposeController — الاقتراح التلقائي عبر CP-SAT (Google OR-Tools).
 * Laravel «ينسّق فقط»: يجمع (الشعبة + الفترات + المقررات + المدرسين + القاعات + الموجود)
 * ثم يمرر إلى Go /propose (الذي يشغّل solver.py). لا قرارات تعارض هنا.
 * النتيجة مسودة للمراجعة — الاعتماد يمر بمسار check→save المعتاد.
 *
 * يدعم: شعبة واحدة، أو كل الشعب المسموحة بنطاق المستخدم (multi-group) —
 * في الوضع المتعدد تُحل الشعب تسلسليًا وموجود كل شعبة يُضاف لانشغال القاعات/المدرسين للتي تليها.
 */
class ProposeController extends Controller
{
    public function __construct(private ProposeGroupSolver $solver) {}

    /**
     * GET /api/admin/propose-timetable/context
     * الشعب ضمن نطاق المستخدم + المقررات + الخطة الدراسية لكل شعبة.
     */
    public function context()
    {
        $groups = Group::query()
            ->join('batches', 'batches.id', '=', 'groups.batch_id')
            ->orderBy('batches.name')
            ->orderByRaw(ArabicDay::groupTrackOrderSql('groups.track'))
            ->get(['groups.id', 'batches.name as batch', 'groups.track', 'groups.student_count', 'batches.program_id']);

        $planItems = DB::table('group_courses')
            ->join('courses', 'courses.id', '=', 'group_courses.course_id')
            ->whereIn('group_courses.group_id', $groups->pluck('id'))
            ->select('group_courses.group_id', 'group_courses.course_id', 'group_courses.sessions_per_week', 'courses.code', 'courses.name')
            ->get();

        $plans = [];
        foreach ($planItems as $item) {
            $plans[(int) $item->group_id][] = [
                'course_id' => (int) $item->course_id,
                'code' => (string) $item->code,
                'name' => (string) $item->name,
                'sessions_per_week' => (int) $item->sessions_per_week,
            ];
        }

        $courses = Course::orderBy('code')->get(['id', 'code', 'name', 'credit_hours']);

        return response()->json([
            'groups' => $groups,
            'courses' => $courses,
            'plans' => $plans,
        ]);
    }

    /**
     * POST /api/admin/propose-timetable
     * body: { group_id?, group_ids?, course_ids?, academic_term? }
     * - group_ids (مصفوفة) ⇒ وضع متعدد: كل شعبة ضمن نطاق المستخدم تُحل تسلسليًا
     * - group_id (رقم)     ⇒ شعبة واحدة (توافق مع النسخة السابقة)
     */
    public function store(Request $request)
    {
        $input = $request->validate([
            'group_id' => ['required_without:group_ids', 'integer', Rule::exists('groups', 'id')],
            'group_ids' => ['required_without:group_id', 'array', 'min:1', 'max:32', 'distinct'],
            'group_ids.*' => ['integer', Rule::exists('groups', 'id')],
            'course_ids' => ['sometimes', 'array'],
            'course_ids.*' => ['integer', Rule::exists('courses', 'id')],
            'academic_term' => ['sometimes', 'string', 'max:20'],
        ]);

        $term = $input['academic_term'] ?? Defaults::DEFAULT_TERM;
        $multi = ! empty($input['group_ids']);
        $groupIds = $multi
            ? array_map('intval', $input['group_ids'])
            : [(int) ($input['group_id'] ?? 0)];

        $groupsById = Group::with('batch')
            ->whereIn('id', $groupIds)
            ->get()
            ->keyBy('id');

        $courseIdsInput = ! empty($input['course_ids']) ? array_map('intval', $input['course_ids']) : null;

        // الموجود العام أسبوعيًا — ينمو مع كل شعبة تُحل (شعبة في الوضع المتعدد لا تحتل قاعة تشغلها سابقتها)
        $existing = $this->weeklyExisting($term);

        $allAssignments = [];
        $allUnplaced = [];
        $groupSummaries = [];
        $totalPlaced = 0;
        $anyFailure = null;

        foreach ($groupIds as $gid) {
            $group = $groupsById->get($gid);
            $batch = Batch::findOrFail($group->batch_id);

            $solved = $this->solver->solveOne($group, $batch, $existing, $courseIdsInput, $term, $multi);
            foreach ($solved['newOccupancy'] as $slot) {
                $existing[] = $slot;
            }

            $groupSummaries[] = $solved['summary'];
            $allAssignments = array_merge($allAssignments, $solved['assignments']);
            $allUnplaced = array_merge($allUnplaced, $solved['unplaced']);
            $totalPlaced += (int) ($solved['summary']['placed'] ?? 0);
            if ($solved['failure'] !== null) {
                $anyFailure = $solved['failure'];
            }
        }

        if ($anyFailure && empty($allAssignments)) {
            return response()->json(['ok' => false, 'message_ar' => $anyFailure], 503);
        }

        // ترتيب نهائي للعرض
        usort($allAssignments, fn ($a, $b) => ($a['group_id'] <=> $b['group_id'])
            ?: strcmp((string) $a['day'], (string) $b['day'])
            ?: strcmp((string) $a['start_time'], (string) $b['start_time']));

        return response()->json([
            'ok' => true,
            'multi' => $multi,
            'status' => count($groupSummaries) === 1 ? (string) $groupSummaries[0]['status'] : 'BATCH',
            'placed' => $totalPlaced,
            'groups' => $groupSummaries,
            'assignments' => $allAssignments,
            'unplaced' => $allUnplaced,
            'academic_term' => $term,
        ]);
    }

    private function weeklyExisting(?string $term = null): array
    {
        $query = Timetable::notArchived()->orderBy('id');
        if ($term !== null) {
            $query->where('academic_term', $term);
        }

        $existing = $query->get(['id', 'day_of_week', 'start_time', 'end_time', 'hall_id', 'faculty_id', 'group_id', 'course_id'])
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'kind' => 'lecture',
                'day_of_week' => (string) $t->day_of_week,
                'start_time' => substr((string) $t->start_time, 0, 8),
                'end_time' => substr((string) $t->end_time, 0, 8),
                'hall_id' => (int) $t->hall_id,
                'faculty_id' => (int) $t->faculty_id,
                'group_id' => (int) $t->group_id,
                'course_id' => (int) $t->course_id,
            ])->all();

        // الامتحانات والحجوزات الموجودة — بدون فلترة بالتيرم حاليًا (تاريخ الامتحان تقاطع اليوم كافي للتداخل الزمني)
        $exams = DB::table('exam_sessions')
            ->whereNull('archived_at')
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'exam_date', 'start_time', 'end_time', 'hall_id', 'group_id', 'course_id'])
            ->map(fn ($e) => [
                'id' => (int) $e->id,
                'kind' => 'exam',
                'day_of_week' => '',
                'exam_date' => (string) $e->exam_date,
                'start_time' => substr((string) $e->start_time, 0, 8),
                'end_time' => substr((string) $e->end_time, 0, 8),
                'hall_id' => (int) $e->hall_id,
                'faculty_id' => 0,
                'group_id' => (int) ($e->group_id ?? 0),
                'course_id' => (int) ($e->course_id ?? 0),
            ])->all();

        $bookings = DB::table('bookings')
            ->where('status', 'active')
            ->get(['id', 'date', 'start_time', 'end_time', 'hall_id', 'group_id'])
            ->map(fn ($b) => [
                'id' => (int) $b->id,
                'kind' => 'booking',
                'day_of_week' => '',
                'exam_date' => (string) $b->date,
                'start_time' => substr((string) $b->start_time, 0, 8),
                'end_time' => substr((string) $b->end_time, 0, 8),
                'hall_id' => (int) $b->hall_id,
                'faculty_id' => 0,
                'group_id' => (int) ($b->group_id ?? 0),
                'course_id' => 0,
            ])->all();

        return array_merge($existing, $exams, $bookings);
    }
}
