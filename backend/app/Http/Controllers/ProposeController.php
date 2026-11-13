<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Course;
use App\Models\Group;
use App\Models\GroupCourse;
use App\Models\Hall;
use App\Models\Period;
use App\Models\Timetable;
use App\Services\Access\FacultyScope;
use App\Services\Engine\EngineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    public function __construct(private EngineClient $engine) {}

    /**
     * GET /api/admin/propose-timetable/context
     * الشعب ضمن نطاق المستخدم + المقررات + الخطة الدراسية لكل شعبة.
     */
    public function context()
    {
        $user = auth()->user();

        $groups = FacultyScope::groupQuery($user)
            ->join('batches', 'batches.id', '=', 'groups.batch_id')
            ->orderBy('batches.name')
            ->orderByRaw("CASE groups.track WHEN 'دبلوم' THEN 1 WHEN 'بكلاريوس' THEN 2 ELSE 3 END")
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

        $user = auth()->user();
        $term = $input['academic_term'] ?? '2026/1';
        $multi = ! empty($input['group_ids']);
        $groupIds = $multi
            ? array_map('intval', $input['group_ids'])
            : [(int) ($input['group_id'] ?? 0)];

        // ── نطاق المجلس: الجامعة كلها — لا قيد كلية (مجلس المدرسين يدير كل الأقسام) ──
        foreach ($groupIds as $gid) {
            if (! FacultyScope::assertGroupInScope($user, $gid)) {
                return response()->json([
                    'ok' => false,
                    'message_ar' => FacultyScope::deniedMessage()." (شعبة #{$gid})",
                ], 403);
            }
        }

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

        foreach ($groupIds as $index => $gid) {
            $group = $groupsById->get($gid);
            $batch = Batch::findOrFail($group->batch_id);
            $programId = (int) $batch->program_id;

            $periods = Period::where('program_id', $programId)->orderBy('day_of_week')->orderBy('sort_order')->get();
            if ($periods->isEmpty()) {
                $groupSummaries[] = ['group_id' => $gid, 'ok' => false, 'status' => 'NO_PERIODS', 'placed' => 0, 'batch' => $batch->name, 'track' => $group->track];

                continue;
            }

            $plan = GroupCourse::where('group_id', $gid)->pluck('sessions_per_week', 'course_id');

            // تحديد المقررات: اختيار صريح > خطة الشعبة > الكل (fallback)
            if ($courseIdsInput !== null) {
                $selection = Course::whereIn('id', $courseIdsInput)->orderBy('code')->get();
                $sessionsOverride = null; // استخدام credit_hours
            } elseif ($plan->isNotEmpty()) {
                $selection = Course::whereIn('id', $plan->keys()->all())->orderBy('code')->get();
                $sessionsOverride = $plan;
            } else {
                $selection = Course::orderBy('code')->get();
                $sessionsOverride = null;
            }

            if ($selection->isEmpty()) {
                $groupSummaries[] = ['group_id' => $gid, 'ok' => false, 'status' => 'NO_COURSES', 'placed' => 0, 'batch' => $batch->name, 'track' => $group->track];

                continue;
            }

            $pools = $this->poolsFor($selection->pluck('id'));
            $payloadCourses = $selection->map(function (Course $course) use ($pools, $sessionsOverride) {
                $pool = ($pools->get($course->id) ?? collect())
                    ->map(fn ($f) => ['id' => (int) $f->id, 'full_name' => $f->full_name])
                    ->values()->all();

                $sessions = $sessionsOverride !== null
                    ? max(1, min(6, (int) $sessionsOverride->get($course->id, $course->credit_hours)))
                    : max(1, min(6, (int) $course->credit_hours));

                return [
                    'course_id' => (int) $course->id,
                    'code' => $course->code,
                    'name' => $course->name,
                    'sessions' => $sessions,
                    'faculty_pool' => $pool,
                ];
            })->values()->all();

            $halls = Hall::where('status', 'متاحة')->orderBy('capacity')
                ->get(['id', 'name', 'hall_type', 'capacity'])
                ->map(fn ($h) => ['id' => (int) $h->id, 'name' => $h->name, 'hall_type' => $h->hall_type, 'capacity' => (int) $h->capacity])
                ->values()->all();

            if (empty($halls)) {
                $groupSummaries[] = ['group_id' => $gid, 'ok' => false, 'status' => 'NO_HALLS', 'placed' => 0, 'batch' => $batch->name, 'track' => $group->track];

                continue;
            }

            $payload = [
                'group' => ['id' => (int) $group->id, 'student_count' => (int) $group->student_count],
                'courses' => $payloadCourses,
                'periods' => $periods->map(fn ($p) => [
                    'period_id' => (int) $p->id,
                    'day' => $p->day_of_week,
                    'start_time' => substr((string) $p->start_time, 0, 8),
                    'end_time' => substr((string) $p->end_time, 0, 8),
                    'label' => $p->label,
                    'sort_order' => (int) $p->sort_order,
                ])->values()->all(),
                'halls' => $halls,
                'existing' => $existing,
                'academic_term' => $term,
                // شعبة واحدة ← 20 ثانية؛ وضع متعدد ← 8 ثوانٍ لكل شعبة (مجموع متوقع < دقيقتين)
                'timeout_seconds' => $multi ? 8 : 20,
            ];

            try {
                $result = $this->engine->propose($payload);
            } catch (\Throwable $e) {
                $anyFailure = 'تعذر محرك الاقتراح — تابع يدويًا أو حاول لاحقًا';
                Log::error('[propose] engine فشل', ['group_id' => $gid, 'error' => $e->getMessage()]);
                $groupSummaries[] = ['group_id' => $gid, 'ok' => false, 'status' => 'ENGINE_ERROR', 'placed' => 0, 'batch' => $batch->name, 'track' => $group->track];

                continue;
            }
            if (! ($result['ok'] ?? false)) {
                $anyFailure = $result['error'] ?? 'تعذر المحرك — تابع يدويًا';
                Log::error('[propose] engine فشل (ok:false)', ['group_id' => $gid, 'error' => $result['error'] ?? null]);
                $groupSummaries[] = ['group_id' => $gid, 'ok' => false, 'status' => 'ENGINE_ERROR', 'placed' => 0, 'batch' => $batch->name, 'track' => $group->track];

                continue;
            }

            $assignments = $result['assignments'] ?? [];
            foreach ($assignments as $a) {
                $allAssignments[] = $a + ['group_id' => $gid];
            }
            foreach (($result['unplaced'] ?? []) as $u) {
                $allUnplaced[] = $u + ['group_id' => $gid];
            }

            $placedHere = (int) ($result['placed'] ?? 0);
            $totalPlaced += $placedHere;
            $groupSummaries[] = [
                'group_id' => $gid,
                'ok' => true,
                'status' => $result['status'] ?? 'UNKNOWN',
                'placed' => $placedHere,
                'batch' => $batch->name,
                'track' => $group->track,
            ];

            // تثبيت التعيينات محليًا كي تكون «مشغولة» للشعب التالية في نفس التشغيلة
            foreach ($assignments as $a) {
                $existing[] = [
                    'id' => 0,
                    'kind' => 'lecture',
                    'day_of_week' => $a['day'],
                    'start_time' => $a['start_time'],
                    'end_time' => $a['end_time'],
                    'hall_id' => (int) $a['hall_id'],
                    'faculty_id' => (int) $a['faculty_id'],
                    'group_id' => 0, // نترك انشغال القاعة/المدرس فقط يمنع التكرار — الشعبة المحددة تملأ جدولها بنفسها
                    'course_id' => (int) $a['course_id'],
                ];
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
        if ($term !== null && $term !== '') {
            $query->where('academic_term', $term);
        }

        return $query->get()->map(fn (Timetable $t) => [
            'id' => (int) $t->id,
            'kind' => 'lecture',
            'day_of_week' => $t->day_of_week,
            'start_time' => substr((string) $t->start_time, 0, 8),
            'end_time' => substr((string) $t->end_time, 0, 8),
            'hall_id' => (int) $t->hall_id,
            'faculty_id' => (int) ($t->faculty_id ?? 0),
            'group_id' => (int) $t->group_id,
            'course_id' => (int) $t->course_id,
        ])->all();
    }

    private function poolsFor($courseIds)
    {
        return DB::table('course_faculty')
            ->join('faculty', 'faculty.id', '=', 'course_faculty.faculty_id')
            ->whereIn('course_faculty.course_id', $courseIds)
            ->select('course_faculty.course_id', 'faculty.id', 'faculty.full_name')
            ->get()
            ->groupBy('course_id');
    }
}
