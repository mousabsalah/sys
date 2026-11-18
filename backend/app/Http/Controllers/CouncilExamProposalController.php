<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\Course;
use App\Models\ExamSession;
use App\Models\Group;
use App\Models\Hall;
use App\Services\Academic\ArabicDay;
use App\Services\Academic\ExamSlots;
use App\Services\Engine\ConflictChecker;
use App\Services\Engine\EngineClient;
use App\Support\Defaults;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * CouncilExamProposalController — «اقتراح الامتحانات التلقائي» (CP-SAT).
 * نفس مفهوم «اقتراح تلقائي» للجدول الدراسي لكن:
 * - الامتحانات فاينل لكل مقرر في الخطة،
 * - على تواريخ حقيقية ضمن مدى يدخله المجلس (ExamSlots: الجمعة مستبعدة)،
 * - بدون أي مراقب — القيود: القاعة + الشعبة + السعة + المتصادم مع الموجود.
 * الناتج مسودة للمراجعة؛ «اعتماد الكل» ينشئ صفوف exam_sessions بحالة pending،
 * وتظهر للاعتماد من شاشة الأدمن «اعتماد الجداول والامتحانات».
 */
class CouncilExamProposalController extends Controller
{
    public function __construct(private EngineClient $engine, private ConflictChecker $checker) {}

    /** GET /api/council/propose-exams/context — الشعب + المقررات + الخطط الدراسية لكل شعبة */
    public function context(): JsonResponse
    {
        $groups = Group::query()
            ->join('batches', 'batches.id', '=', 'groups.batch_id')
            ->whereNull('batches.deleted_at')
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
     * POST /api/council/propose-exams
     * body: { group_id?, group_ids?, course_ids?, from: 'YYYY-MM-DD', to: 'YYYY-MM-DD', academic_term? }
     */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'group_id' => ['required_without:group_ids', 'integer', Rule::exists('groups', 'id')],
            'group_ids' => ['required_without:group_id', 'array', 'min:1', 'max:32', 'distinct'],
            'group_ids.*' => ['integer', Rule::exists('groups', 'id')],
            'course_ids' => ['sometimes', 'array'],
            'course_ids.*' => ['integer', Rule::exists('courses', 'id')],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'academic_term' => ['sometimes', 'string', 'max:20'],
        ], [
            'from.required' => 'تاريخ بداية مدى الامتحانات مطلوب.',
            'to.required' => 'تاريخ نهاية مدى الامتحانات مطلوب.',
            'to.after_or_equal' => 'تاريخ النهاية يجب ألا يسبق تاريخ البداية.',
        ]);

        $term = $input['academic_term'] ?? Defaults::DEFAULT_TERM;
        $daysDelta = Carbon::parse($input['from'])->diffInDays(Carbon::parse($input['to']));
        if ($daysDelta > 30) {
            return response()->json(['ok' => false, 'message_ar' => 'مدى الامتحانات لا يتجاوز 30 يومًا — ضيّق المدى.'], 422);
        }

        $multi = ! empty($input['group_ids']);
        $groupIds = $multi ? array_map('intval', $input['group_ids']) : [(int) ($input['group_id'] ?? 0)];

        $slots = ExamSlots::build($input['from'], $input['to']);
        if ($slots === []) {
            return response()->json(['ok' => false, 'message_ar' => 'لا توجد أيام امتحان صالحة في المدى — المدى كله جمعة؟'], 422);
        }

        $groupsById = Group::with('batch')->whereIn('id', $groupIds)->get()->keyBy('id');
        $courseIdsInput = ! empty($input['course_ids']) ? array_map('intval', $input['course_ids']) : null;

        $existing = $this->existingWithinRange($input['from'], $input['to'], $term);
        $allAssignments = [];
        $allUnplaced = [];
        $groupSummaries = [];
        $totalPlaced = 0;
        $anyFailure = null;

        foreach ($groupIds as $gid) {
            /** @var Group|null $group */
            $group = $groupsById->get($gid);
            if (! $group) {
                continue;
            }
            $batch = Batch::findOrFail($group->batch_id);

            // تحديد المقررات: اختيار صريح > خطة الشعبة > (لا اقتراح بلا مقررات)
            if ($courseIdsInput !== null) {
                $selection = Course::whereIn('id', $courseIdsInput)->orderBy('code')->get();
            } else {
                $plan = DB::table('group_courses')->where('group_id', $gid)->pluck('course_id')->all();
                $selection = $plan === [] ? collect() : Course::whereIn('id', $plan)->orderBy('code')->get();
            }
            if ($selection->isEmpty()) {
                $groupSummaries[] = ['group_id' => (int) $gid, 'ok' => false, 'status' => 'NO_COURSES', 'placed' => 0, 'batch' => $batch->name, 'track' => (string) $group->track];

                continue;
            }

            $payloadCourses = $selection->map(fn ($c) => [
                'course_id' => (int) $c->id,
                'code' => (string) $c->code,
                'name' => (string) $c->name,
                'sessions' => 1, // فاينل واحد لكل مقرر
            ])->values()->all();

            $halls = Hall::where('status', 'متاحة')->orderBy('capacity')
                ->get(['id', 'name', 'hall_type', 'capacity'])
                ->map(fn ($h) => ['id' => (int) $h->id, 'name' => (string) $h->name, 'hall_type' => (string) $h->hall_type, 'capacity' => (int) $h->capacity])
                ->values()->all();

            if ($halls === []) {
                $groupSummaries[] = ['group_id' => (int) $gid, 'ok' => false, 'status' => 'NO_HALLS', 'placed' => 0, 'batch' => $batch->name, 'track' => (string) $group->track];

                continue;
            }

            $payload = [
                'group' => ['id' => (int) $gid, 'student_count' => (int) $group->student_count],
                'courses' => $payloadCourses,
                'exam_slots' => $slots,
                'halls' => $halls,
                'existing' => $existing,
                'academic_term' => $term,
                'timeout_seconds' => $multi ? 8 : 20,
            ];

            try {
                $result = $this->engine->proposeExams($payload);
            } catch (\Throwable $e) {
                Log::error('[propose-exams] engine فشل', ['group_id' => (int) $gid, 'error' => $e->getMessage()]);
                $groupSummaries[] = ['group_id' => (int) $gid, 'ok' => false, 'status' => 'ENGINE_ERROR', 'placed' => 0, 'batch' => $batch->name, 'track' => (string) $group->track];
                $anyFailure = 'تعذر محرك الاقتراح — تابع يدويًا أو حاول لاحقًا';

                continue;
            }

            if (! ($result['ok'] ?? false)) {
                $groupSummaries[] = ['group_id' => (int) $gid, 'ok' => false, 'status' => 'ENGINE_ERROR', 'placed' => 0, 'batch' => $batch->name, 'track' => (string) $group->track];
                $anyFailure = $result['error'] ?? 'تعذر المحرك — تابع يدويًا';

                continue;
            }

            $tagged = array_map(fn ($a) => $a + ['group_id' => (int) $gid], $result['assignments'] ?? []);
            $placedHere = count($tagged);

            // القاعة مشغولة بتلك اللحظة الآن للشعب التالية
            foreach ($tagged as $a) {
                $existing[] = [
                    'id' => 0,
                    'kind' => 'exam',
                    'day_of_week' => '',
                    'exam_date' => (string) $a['exam_date'],
                    'start_time' => (string) $a['start_time'],
                    'end_time' => (string) $a['end_time'],
                    'hall_id' => (int) $a['hall_id'],
                    'faculty_id' => 0,
                    'group_id' => 0,
                    'course_id' => (int) $a['course_id'],
                ];
            }

            $groupSummaries[] = ['group_id' => (int) $gid, 'ok' => true, 'status' => (string) ($result['status'] ?? 'UNKNOWN'), 'placed' => $placedHere, 'batch' => $batch->name, 'track' => (string) $group->track];
            $allAssignments = array_merge($allAssignments, $tagged);
            $allUnplaced = array_merge($allUnplaced, array_map(fn ($u) => $u + ['group_id' => (int) $gid], $result['unplaced'] ?? []));
            $totalPlaced += $placedHere;
        }

        if ($anyFailure !== null && $allAssignments === []) {
            return response()->json(['ok' => false, 'message_ar' => $anyFailure], 503);
        }

        usort($allAssignments, fn ($a, $b) => ($a['group_id'] <=> $b['group_id'])
            ?: strcmp((string) $a['exam_date'], (string) $b['exam_date'])
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
            'from' => $input['from'],
            'to' => $input['to'],
        ]);
    }

    /**
     * POST /api/council/propose-exams/apply
     * body: { assignments: [...] } — فاينل في معاملة وحدة؛ كل سطر يمر عبر commitExam (قفل + فحص + عزل).
     */
    public function apply(Request $request): JsonResponse
    {
        $input = $request->validate([
            'assignments' => ['required', 'array', 'min:1', 'max:500'],
            'assignments.*.course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
            'assignments.*.group_id' => ['required', 'integer', Rule::exists('groups', 'id')],
            'assignments.*.hall_id' => ['required', 'integer', Rule::exists('halls', 'id')],
            'assignments.*.exam_date' => ['required', 'date', 'after_or_equal:today'],
            'assignments.*.start_time' => ['required', 'date_format:H:i:s'],
            'assignments.*.end_time' => ['required', 'date_format:H:i:s', 'after:start_time'],
            'assignments.*.course_code' => ['sometimes', 'string', 'max:20'],
            'assignments.*.academic_term' => ['sometimes', 'string', 'max:20'],
        ]);

        $user = auth()->user();
        $conflicts = [];
        $created = 0;

        try {
            DB::transaction(function () use (&$conflicts, &$created, $input, $user) {
                foreach ($input['assignments'] as $i => $item) {
                    $result = $this->checker->commitExam([
                        'course_id' => (int) $item['course_id'],
                        'group_id' => (int) $item['group_id'],
                        'hall_id' => (int) $item['hall_id'],
                        'exam_date' => (string) $item['exam_date'],
                        'start_time' => (string) $item['start_time'],
                        'end_time' => (string) $item['end_time'],
                        'exam_type' => 'فاينل',
                        'academic_term' => $item['academic_term'] ?? Defaults::DEFAULT_TERM,
                        'user_id' => $user->id,
                    ]);

                    if (! ($result['ok'] ?? false)) {
                        $conflicts[] = [
                            'index' => $i,
                            'group_id' => (int) $item['group_id'],
                            'course_id' => (int) $item['course_id'],
                            'course_code' => $item['course_code'] ?? null,
                            'exam_date' => (string) $item['exam_date'],
                            'start_time' => (string) $item['start_time'],
                            'message_ar' => $result['message_ar'] ?? 'تعارض',
                            'violations' => $result['verdict']['violations'] ?? [],
                        ];
                        throw new \RuntimeException('BULK_CONFLICT');
                    }
                    $created++;
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'BULK_CONFLICT') {
                return response()->json([
                    'ok' => false,
                    'message_ar' => 'وُجد تعارض — لم يُحفظ أي امتحان (الاعتماد الكلي أو لا شيء). عدّل ثم حاول مجددًا.',
                    'created' => 0,
                    'conflicts' => $conflicts,
                ], 422);
            }
            throw $e;
        }

        return response()->json(['ok' => true, 'created' => $created], 201);
    }

    /** الموجود في المدى: امتحانات + حجوزات — لفصل واحد.
     *  المحاضرات الأسبوعية لا تُمنع تعارضها مع الامتحانات (حسب قيد المالك: القاعة + الشعبة + السعة فقط). */
    /** @return array<int, array<string, mixed>> */
    private function existingWithinRange(string $from, string $to, string $term): array
    {
        $examsQ = ExamSession::notArchived()->where('status', '!=', 'cancelled')->whereBetween('exam_date', [$from, $to]);
        if ($term !== '') {
            $examsQ->where('academic_term', $term);
        }
        $existing = $examsQ->get(['id', 'exam_date', 'start_time', 'end_time', 'hall_id', 'group_id', 'course_id'])
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

        $bookingsQ = Booking::active()->whereBetween('date', [$from, $to]);
        $bookings = $bookingsQ->get(['id', 'date', 'start_time', 'end_time', 'hall_id'])
            ->map(fn ($b) => [
                'id' => (int) $b->id,
                'kind' => 'booking',
                'day_of_week' => '',
                'exam_date' => (string) $b->date,
                'start_time' => substr((string) $b->start_time, 0, 8),
                'end_time' => substr((string) $b->end_time, 0, 8),
                'hall_id' => (int) $b->hall_id,
                'faculty_id' => 0,
                'group_id' => 0,
                'course_id' => 0,
            ])->all();

        return array_merge($existing, $bookings);
    }
}
