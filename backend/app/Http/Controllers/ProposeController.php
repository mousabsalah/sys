<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Course;
use App\Models\Group;
use App\Models\Hall;
use App\Models\Period;
use App\Models\Timetable;
use App\Services\Engine\EngineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ProposeController — الاقتراح التلقائي عبر CP-SAT (Google OR-Tools).
 * Laravel «ينسّق فقط»: يجمع (الشعبة + الفترات + المقررات + المدرسين + القاعات + الموجود)
 * ثم يمرر إلى Go /propose (الذي يشغّل solver.py). لا قرارات تعارض هنا.
 * النتيجة مسودة للمراجعة — الاعتماد يمر بمسار check→save المعتاد.
 */
class ProposeController extends Controller
{
    public function __construct(private EngineClient $engine) {}

    /**
     * GET /api/admin/propose-timetable/context
     * يرجع قوائم الاختيار لشاشة الاقتراح — متاح لـ admin وdept.
     */
    public function context()
    {
        $groups = DB::table('groups')
            ->join('batches', 'batches.id', '=', 'groups.batch_id')
            ->orderBy('batches.name')
            ->orderByRaw("FIELD(groups.track, 'دبلوم', 'بكلاريوس')")
            ->get(['groups.id', 'batches.name as batch', 'groups.track', 'groups.student_count', 'batches.program_id']);

        $courses = Course::orderBy('code')->get(['id', 'code', 'name', 'credit_hours']);

        return response()->json([
            'groups' => $groups,
            'courses' => $courses,
        ]);
    }

    /**
     * POST /api/admin/propose-timetable
     * body: { group_id, course_ids?: number[], academic_term?: string }
     */
    public function store(Request $request)
    {
        $input = $request->validate([
            'group_id' => ['required', 'integer', Rule::exists('groups', 'id')],
            'course_ids' => ['sometimes', 'array'],
            'course_ids.*' => ['integer', Rule::exists('courses', 'id')],
            'academic_term' => ['sometimes', 'string', 'max:20'],
        ]);

        $group = Group::with('batch')->findOrFail($input['group_id']);
        $batch = Batch::findOrFail($group->batch_id);
        $programId = (int) $batch->program_id;
        $term = $input['academic_term'] ?? '2026/1';

        $periods = Period::where('program_id', $programId)
            ->orderBy('day_of_week')
            ->orderBy('sort_order')
            ->get();

        if ($periods->isEmpty()) {
            return response()->json([
                'ok' => false,
                'message_ar' => 'لا توجد فترات مسجلة لهذا البرنامج — لا يمكن الاقتراح',
            ], 422);
        }

        $courseQuery = Course::query();
        if (! empty($input['course_ids'])) {
            $courseQuery->whereIn('id', $input['course_ids']);
        }
        $courses = $courseQuery->orderBy('code')->get();

        $courseIds = $courses->pluck('id');
        $pools = DB::table('course_faculty')
            ->join('faculty', 'faculty.id', '=', 'course_faculty.faculty_id')
            ->whereIn('course_faculty.course_id', $courseIds)
            ->select('course_faculty.course_id', 'faculty.id', 'faculty.full_name')
            ->get()
            ->groupBy('course_id');

        $payloadCourses = $courses->map(function (Course $course) use ($pools) {
            $pool = $pools->get($course->id, collect())
                ->map(fn ($f) => ['id' => (int) $f->id, 'full_name' => $f->full_name])
                ->values()
                ->all();

            return [
                'course_id' => (int) $course->id,
                'code' => $course->code,
                'name' => $course->name,
                'sessions' => max(1, min(4, (int) $course->credit_hours)),
                'faculty_pool' => $pool,
            ];
        })->values()->all();

        $halls = Hall::where('status', 'متاحة')
            ->orderBy('capacity')
            ->get(['id', 'name', 'hall_type', 'capacity'])
            ->map(fn ($h) => [
                'id' => (int) $h->id,
                'name' => $h->name,
                'hall_type' => $h->hall_type,
                'capacity' => (int) $h->capacity,
            ])->values()->all();

        if (empty($halls)) {
            return response()->json(['ok' => false, 'message_ar' => 'لا توجد قاعات متاحة'], 422);
        }

        // الموجود أسبوعيًا — يشمل محاضرة الشعبة + انشغال القاعة/المدرس في تلك الفترة
        $existing = Timetable::orderBy('id')->get()->map(function (Timetable $t) {
            return [
                'id' => (int) $t->id,
                'kind' => 'lecture',
                'day_of_week' => $t->day_of_week,
                'start_time' => substr((string) $t->start_time, 0, 8),
                'end_time' => substr((string) $t->end_time, 0, 8),
                'hall_id' => (int) $t->hall_id,
                'faculty_id' => (int) ($t->faculty_id ?? 0),
                'group_id' => (int) $t->group_id,
                'course_id' => (int) $t->course_id,
            ];
        })->all();

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
        ];

        $result = $this->engine->propose($payload);

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message_ar' => $result['error'] ?? 'تعذر المحرك — تابع يدويًا',
            ], 503);
        }

        return response()->json([
            'ok' => true,
            'group' => [
                'id' => (int) $group->id,
                'batch_id' => (int) $batch->id,
                'batch' => $batch->name,
                'track' => $group->track,
                'student_count' => (int) $group->student_count,
                'program_id' => $programId,
            ],
            'status' => $result['status'] ?? 'UNKNOWN',
            'placed' => $result['placed'] ?? 0,
            'assignments' => $result['assignments'] ?? [],
            'unplaced' => $result['unplaced'] ?? [],
            'academic_term' => $term,
        ]);
    }
}
