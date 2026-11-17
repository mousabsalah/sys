<?php

namespace App\Services\Engine;

use App\Models\Batch;
use App\Models\Course;
use App\Models\Group;
use App\Models\GroupCourse;
use App\Models\Hall;
use App\Models\Period;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ProposeGroupSolver — محاولة حل شعبة واحدة عبر CP-SAT: بناء الحمولة →
 * استدعاء محرك Go → جمع التعيينات والملخص. لا قرارات هنا — مجرد تنسيق.
 */
final class ProposeGroupSolver
{
    public function __construct(private EngineClient $engine) {}

    /**
     * @param  array<int, array<string, mixed>>  $existing  الموجود الأسبوعي الحالي (يُوسَّع بالشعب المحلولة تباعًا)
     * @param  array<int, int>|null  $courseIdsInput  اختيار صريح للمقررات — null = استخدم الخطة ثم الكل
     * @return array{summary: array<string, mixed>, assignments: array<int, array<string, mixed>>, unplaced: array<int, array<string, mixed>>, failure: ?string, newOccupancy: array<int, array<string, mixed>>}
     */
    public function solveOne(Group $group, Batch $batch, array $existing, ?array $courseIdsInput, string $term, bool $multi): array
    {
        $gid = (int) $group->id;
        $programId = (int) $batch->program_id;

        $periods = Period::where('program_id', $programId)->orderBy('day_of_week')->orderBy('sort_order')->get();
        if ($periods->isEmpty()) {
            return $this->finish(
                ['group_id' => $gid, 'ok' => false, 'status' => 'NO_PERIODS', 'placed' => 0, 'batch' => $batch->name, 'track' => $group->track],
                [], [], null, []
            );
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
            return $this->finish(
                ['group_id' => $gid, 'ok' => false, 'status' => 'NO_COURSES', 'placed' => 0, 'batch' => $batch->name, 'track' => $group->track],
                [], [], null, []
            );
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
            return $this->finish(
                ['group_id' => $gid, 'ok' => false, 'status' => 'NO_HALLS', 'placed' => 0, 'batch' => $batch->name, 'track' => $group->track],
                [], [], null, []
            );
        }

        $payload = [
            'group' => ['id' => $gid, 'student_count' => (int) $group->student_count],
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
            Log::error('[propose] engine فشل', ['group_id' => $gid, 'error' => $e->getMessage()]);

            return $this->finish(
                ['group_id' => $gid, 'ok' => false, 'status' => 'ENGINE_ERROR', 'placed' => 0, 'batch' => $batch->name, 'track' => $group->track],
                [], [], 'تعذر محرك الاقتراح — تابع يدويًا أو حاول لاحقًا', []
            );
        }
        if (! ($result['ok'] ?? false)) {
            Log::error('[propose] engine فشل (ok:false)', ['group_id' => $gid, 'error' => $result['error'] ?? null]);

            return $this->finish(
                ['group_id' => $gid, 'ok' => false, 'status' => 'ENGINE_ERROR', 'placed' => 0, 'batch' => $batch->name, 'track' => $group->track],
                [], [], $result['error'] ?? 'تعذر المحرك — تابع يدويًا', []
            );
        }

        $assignments = $result['assignments'] ?? [];
        $placedHere = (int) ($result['placed'] ?? 0);

        $taggedAssignments = array_map(fn ($a) => $a + ['group_id' => $gid], $assignments);
        $taggedUnplaced = array_map(fn ($u) => $u + ['group_id' => $gid], $result['unplaced'] ?? []);

        // تثبيت التعيينات محليًا كي تكون «مشغولة» للشعب التالية في نفس التشغيلة
        $newOccupancy = array_map(fn ($a) => [
            'id' => 0,
            'kind' => 'lecture',
            'day_of_week' => $a['day'],
            'start_time' => $a['start_time'],
            'end_time' => $a['end_time'],
            'hall_id' => (int) $a['hall_id'],
            'faculty_id' => (int) $a['faculty_id'],
            'group_id' => 0, // نترك انشغال القاعة/المدرس فقط يمنع التكرار — الشعبة المحددة تملأ جدولها بنفسها
            'course_id' => (int) $a['course_id'],
        ], $assignments);

        return $this->finish(
            [
                'group_id' => $gid,
                'ok' => true,
                'status' => $result['status'] ?? 'UNKNOWN',
                'placed' => $placedHere,
                'batch' => $batch->name,
                'track' => $group->track,
            ],
            $taggedAssignments,
            $taggedUnplaced,
            null,
            $newOccupancy
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $pools  [course_id => [faculty...]]
     */
    private function poolsFor($courseIds)
    {
        return DB::table('course_faculty')
            ->join('faculty', 'faculty.id', '=', 'course_faculty.faculty_id')
            ->whereIn('course_faculty.course_id', $courseIds)
            ->select('course_faculty.course_id', 'faculty.id', 'faculty.full_name')
            ->get()
            ->groupBy('course_id');
    }

    /** @return array{summary: array<string, mixed>, assignments: array<int, array<string, mixed>>, unplaced: array<int, array<string, mixed>>, failure: ?string, newOccupancy: array<int, array<string, mixed>>} */
    private function finish(array $summary, array $assignments, array $unplaced, ?string $failure, array $newOccupancy): array
    {
        return [
            'summary' => $summary,
            'assignments' => $assignments,
            'unplaced' => $unplaced,
            'failure' => $failure,
            'newOccupancy' => $newOccupancy,
        ];
    }
}
