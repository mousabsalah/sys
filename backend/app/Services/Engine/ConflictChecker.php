<?php

namespace App\Services\Engine;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\CourseFaculty;
use App\Models\ExamSession;
use App\Models\Faculty;
use App\Models\Group;
use App\Models\Hall;
use App\Models\Semester;
use App\Models\Timetable;
use App\Services\Academic\ArabicDay;
use App\Services\Academic\SemesterResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// (Bookings, Timetable, ExamSession يُستدعَون مباشرةً أعلاه)

/**
 * ConflictChecker — خط أنابيب فحص التعارض الكامل:
 *   1) يقرأ حقائق القاعة/الشعبة/المقرر/الأستاذ من DB
 *   2) يجمع المشحونات المتاحة في نفس الفترة
 *   3) يستدعي Go syst-engine عبر EngineClient
 *   4) يمرر النتيجة للطلّاب
 *
 * لا يتخذ قرار التعارض بنفسه — القرار النهائي للـ engine.
 */
class ConflictChecker
{
    public function __construct(private readonly EngineClient $engine) {}

    // ══════════════════════════════════════════════════
    //  الواجهة العامة
    // ══════════════════════════════════════════════════

    public function checkTimetable(array $input): array
    {
        $ctx = $this->resolveCommon($input);
        $day = $this->dayOfWeek($input);
        $term = $input['academic_term'] ?? null;

        $existing = $this->existingSlots(hallId: $ctx['hall_id'], dayOfWeek: $day, examDate: null, term: $term);

        $payload = $this->buildPayload('lecture', $input, $day, $ctx, $existing);

        return $this->engine->check($payload);
    }

    public function checkExam(array $input): array
    {
        $ctx = $this->resolveCommon($input);
        $examDate = $input['exam_date'] ?? throw new RuntimeException('exam_date مطلوب للامتحان');
        $term = $input['academic_term'] ?? null;

        $day = ArabicDay::of(Carbon::parse($examDate));

        $existing = $this->existingSlots(hallId: $ctx['hall_id'], dayOfWeek: $day, examDate: $examDate, term: $term);

        $payload = $this->buildPayload('exam', $input, $day, $ctx, $existing);
        $payload['exam_date'] = $examDate;

        return $this->engine->check($payload);
    }

    public function checkBooking(array $input): array
    {
        $ctx = $this->resolveCommon($input);
        $date = $input['date'] ?? throw new RuntimeException('date مطلوب للحجز');
        $term = $input['academic_term'] ?? null;

        $day = ArabicDay::of(Carbon::parse($date));

        $existing = $this->existingSlots(hallId: $ctx['hall_id'], dayOfWeek: $day, examDate: $date, term: $term);

        $payload = $this->buildPayload('booking', $input, $day, $ctx, $existing);
        $payload['exam_date'] = $date;

        return $this->engine->check($payload);
    }

    // تنفيذ الحفظ الفعلي داخل معاملة الكتابة (يُستدعى من Controller)
    public function commitTimetable(array $input): array
    {
        $ctx = $this->resolveCommon($input);
        $day = $this->dayOfWeek($input);
        $term = $input['academic_term'] ?? null;
        $groupId = (int) $input['group_id'];

        return DB::transaction(function () use ($input, $day, $ctx, $term, $groupId) {
            // 🔒 قفل صف القاعة ← ثم الشعبة ← ثم المدرس — نفس ترتيب القفل في كل commit لمنع deadlocks
            Hall::where('id', $ctx['hall_id'])->lockForUpdate()->firstOrFail();
            Group::where('id', $groupId)->lockForUpdate()->firstOrFail();
            if (! empty($input['faculty_id'])) {
                Faculty::where('id', (int) $input['faculty_id'])->lockForUpdate()->firstOrFail();
            }

            // إعادة فحص مع بيانات حديثة داخل المعاملة
            $existing = $this->existingSlots(hallId: $ctx['hall_id'], dayOfWeek: $day, examDate: null, term: $term);
            $payload = $this->buildPayload('lecture', $input, $day, $ctx, $existing);
            $verdict = $this->engine->check($payload);

            if ($verdict['conflict']) {
                return ['ok' => false, 'verdict' => $verdict, 'message_ar' => 'تعارض مع التحديثات الجارية'];
            }

            $semesterId = SemesterResolver::activeForBatch((int) ($ctx['batch_id'] ?? 0));

            $timetable = Timetable::create([
                'course_id' => $input['course_id'],
                'faculty_id' => $input['faculty_id'] ?? null,
                'group_id' => $input['group_id'],
                'hall_id' => $ctx['hall_id'],
                'day_of_week' => $day,
                'start_time' => $input['start_time'],
                'end_time' => $input['end_time'],
                'academic_term' => $input['academic_term'],
                'semester_id' => $semesterId,
            ]);

            // سجل تحديثات الدفعة — مصاريف البنية «جديد»
            DB::table('timetable_updates')->insert([
                'group_id' => $input['group_id'],
                'timetable_id' => $timetable->id,
                'update_type' => 'add',
                'description' => 'إضافة محاضرة',
                'created_at' => now(),
            ]);

            return ['ok' => true, 'timetable' => $timetable];
        });
    }

    public function commitExam(array $input): array
    {
        $ctx = $this->resolveCommon($input);
        $examDate = $input['exam_date'] ?? throw new RuntimeException('exam_date مطلوب');
        $term = $input['academic_term'] ?? null;
        $day = ArabicDay::of(Carbon::parse($examDate));
        $groupId = (int) $input['group_id'];

        return DB::transaction(function () use ($input, $examDate, $day, $ctx, $term, $groupId) {
            Hall::where('id', $ctx['hall_id'])->lockForUpdate()->firstOrFail();
            Group::where('id', $groupId)->lockForUpdate()->firstOrFail();

            $existing = $this->existingSlots(hallId: $ctx['hall_id'], dayOfWeek: $day, examDate: $examDate, term: $term);
            $payload = $this->buildPayload('exam', $input, $day, $ctx, $existing);
            $payload['exam_date'] = $examDate;
            $verdict = $this->engine->check($payload);

            if ($verdict['conflict']) {
                return ['ok' => false, 'verdict' => $verdict, 'message_ar' => 'تعارض — الامتحان تعذّر الحفظ'];
            }

            $semesterId = SemesterResolver::activeForBatch((int) ($ctx['batch_id'] ?? 0));

            $exam = ExamSession::create([
                'course_id' => $input['course_id'],
                'group_id' => $input['group_id'],
                'hall_id' => $ctx['hall_id'],
                'exam_date' => $examDate,
                'start_time' => $input['start_time'],
                'end_time' => $input['end_time'],
                'exam_type' => $input['exam_type'] ?? 'فاينل',
                'academic_term' => $input['academic_term'],
                'semester_id' => $semesterId,
            ]);

            DB::table('exam_updates')->insert([
                'group_id' => $input['group_id'],
                'exam_session_id' => $exam->id,
                'update_type' => 'add',
                'description' => 'إضافة امتحان',
                'created_at' => now(),
            ]);

            return ['ok' => true, 'exam' => $exam];
        });
    }

    public function commitBooking(array $input): array
    {
        $ctx = $this->resolveCommon($input);
        $date = $input['date'] ?? throw new RuntimeException('date مطلوب');
        $term = $input['academic_term'] ?? null;
        $day = ArabicDay::of(Carbon::parse($date));

        return DB::transaction(function () use ($input, $date, $day, $ctx, $term) {
            Hall::where('id', $ctx['hall_id'])->lockForUpdate()->firstOrFail();

            // حجز قد يخدم فصلًا واحدًا فقط — ننسبه للفصل الوحيد النشط الذي يغطي تاريخه، وإلا يظل NULL
            $dateWindowSemesters = Semester::query()
                ->whereNull('archived_at')
                ->where('start_date', '<=', $date)
                ->where('end_date', '>=', $date)
                ->pluck('id');
            $semesterId = $dateWindowSemesters->count() === 1 ? (int) $dateWindowSemesters->first() : null;

            $existing = $this->existingSlots(hallId: $ctx['hall_id'], dayOfWeek: $day, examDate: $date, term: $term);
            $payload = $this->buildPayload('booking', $input, $day, $ctx, $existing);
            $payload['exam_date'] = $date;
            $verdict = $this->engine->check($payload);

            if ($verdict['conflict']) {
                return ['ok' => false, 'verdict' => $verdict, 'message_ar' => 'القاعة محجوزة في هذا الوقت'];
            }

            $booking = Booking::create([
                'hall_id' => $ctx['hall_id'],
                'date' => $date,
                'start_time' => $input['start_time'],
                'end_time' => $input['end_time'],
                'course_id' => $input['course_id'] ?? null,
                'activity_name' => $input['activity_name'] ?? null,
                'user_id' => $input['user_id'],
                'status' => 'confirmed',
                'semester_id' => $semesterId,
            ]);

            return ['ok' => true, 'booking' => $booking];
        });
    }

    // اللحظة الحالية — للحراس: القاعات الخالية الآن
    public function currentFreeHalls(): array
    {
        $now = now();
        $today = ArabicDay::of($now);
        $nowTime = $now->format('H:i:s');

        $busyHallIds = Timetable::notArchived()
            ->where('day_of_week', $today)
            ->where('start_time', '<=', $nowTime)->where('end_time', '>', $nowTime)
            ->pluck('hall_id')
            ->merge(ExamSession::notArchived()
                ->whereDate('exam_date', $now->toDateString())
                ->where('start_time', '<=', $nowTime)->where('end_time', '>', $nowTime)
                ->pluck('hall_id'))
            ->merge(Booking::active()
                ->whereDate('date', $now->toDateString())
                ->where('start_time', '<=', $nowTime)->where('end_time', '>', $nowTime)
                ->pluck('hall_id'))
            ->unique()->values();

        // VB: آخر قفل لكل قاعة يُحسم في SQL (index scan) بدل سحب كل التاريخ في PHP
        $lockedIds = DB::table('guard_locks as g')
            ->whereIn('g.id', function ($q) {
                $q->selectRaw('MAX(id)')->from('guard_locks')->groupBy('hall_id');
            })
            ->where('g.action', 'lock')
            ->pluck('g.hall_id');

        $free = Hall::whereNotIn('id', $busyHallIds)->whereNotIn('id', $lockedIds)->get();
        $all = Hall::all();

        return [
            'now' => $now->format('Y-m-d H:i:s'),
            'free_halls' => $free->map->only(['id', 'name', 'capacity', 'hall_type']),
            'busy_count' => $busyHallIds->count(),
            'locked_count' => $lockedIds->count(),
            'total_halls' => $all->count(),
        ];
    }

    // ══════════════════════════════════════════════════
    //  أدوات (private)
    // ══════════════════════════════════════════════════

    private function resolveCommon(array $input): array
    {
        $hall = Hall::findOrFail($input['hall_id']);
        // الحجز اليدوي قد لا يرتبط بشعبة محددة — group_id=0 مع عدد طلاب=0 (لا يُطلق فحص السعة)
        $group = ! empty($input['group_id']) ? Group::findOrFail($input['group_id']) : null;
        $batch = $group ? Batch::findOrFail($group->batch_id) : null;

        // سعة القاعة مقابل عدد الطلاب للشعبة
        $facultyId = (int) ($input['faculty_id'] ?? 0);
        $courseId = (int) ($input['course_id'] ?? 0);
        $eligible = null;
        if ($courseId > 0) {
            if ($facultyId > 0) {
                $eligible = CourseFaculty::where('course_id', $courseId)
                    ->where('faculty_id', $facultyId)
                    ->exists();
            }
        }

        return [
            'hall_id' => (int) $hall->id,
            'hall_status' => (string) $hall->status,
            'hall_capacity' => (int) $hall->capacity,
            'hall_type' => (string) $hall->hall_type,
            'program_id' => $batch ? (int) $batch->program_id : 0,
            'group_id' => $group ? (int) $group->id : 0,
            'group_student_count' => $group ? (int) $group->student_count : 0,
            'batch_id' => $batch ? (int) $batch->id : 0,
            'course_id' => $courseId,
            'course_difficulty' => 3,
            'faculty_id' => $facultyId,
            'eligible' => $eligible,
        ];
    }

    private function dayOfWeek(array $input): string
    {
        if (! empty($input['day_of_week'])) {
            return (string) $input['day_of_week'];
        }
        if (! empty($input['date'])) {
            return ArabicDay::of(Carbon::parse($input['date']));
        }
        $date = $input['exam_date'] ?? throw new RuntimeException('اليوم الأسبوعي مطلوب');

        return ArabicDay::of(Carbon::parse($date));
    }

    private function existingSlots(int $hallId, string $dayOfWeek, ?string $examDate, ?string $term = null): array
    {
        $slots = [];

        // الجداول — بدون فصول مؤرشفة، وبنفس الفصل إن حُددت الصيغة، وأعمدة مطلوبة فقط
        $lectureQuery = Timetable::notArchived()->where('day_of_week', $dayOfWeek)
            ->select('id', 'day_of_week', 'start_time', 'end_time', 'hall_id', 'faculty_id', 'group_id', 'course_id');
        if ($term !== null && $term !== '') {
            $lectureQuery->where('academic_term', $term);
        }
        foreach ($lectureQuery->get() as $t) {
            $slots[] = [
                'id' => (int) $t->id,
                'kind' => 'lecture',
                'day_of_week' => (string) $t->day_of_week,
                'exam_date' => '',
                'start_time' => substr((string) $t->start_time, 0, 8),
                'end_time' => substr((string) $t->end_time, 0, 8),
                'hall_id' => (int) $t->hall_id,
                'faculty_id' => (int) $t->faculty_id,
                'group_id' => (int) $t->group_id,
                'course_id' => (int) $t->course_id,
            ];
        }

        // الحجوزات — مؤكدة وغير مؤرشفة فقط
        if ($examDate !== null) {
            // حجز لفترة محددة / امتحان: نفس اليوم + نفس القاعة يكفي
            $bookings = Booking::active()->whereDate('date', $examDate)->where('hall_id', $hallId)->get();
        } else {
            // محاضرة أسبوعية متكررة: الحجوزات المستقبلية من نفس يوم الأسبوع فقط
            $bookings = Booking::active()
                ->where('date', '>=', now()->subDays(30)->toDateString())
                ->get(['id', 'date', 'start_time', 'end_time', 'hall_id', 'course_id'])
                ->filter(
                    fn ($b) => ArabicDay::of(Carbon::parse($b->date)) === $dayOfWeek
                )->values();
        }
        foreach ($bookings as $b) {
            $slots[] = [
                'id' => (int) $b->id,
                'kind' => 'booking',
                'day_of_week' => ArabicDay::of(Carbon::parse($b->date)),
                'exam_date' => (string) $b->date,
                'start_time' => substr((string) $b->start_time, 0, 8),
                'end_time' => substr((string) $b->end_time, 0, 8),
                'hall_id' => (int) $b->hall_id,
                'faculty_id' => 0,
                'group_id' => 0,
                'course_id' => (int) ($b->course_id ?? 0),
            ];
        }

        // الامتحانات — بدون فصول مؤرشفة وأعمدة مطلوبة فقط
        $examQuery = ExamSession::notArchived()
            ->select('id', 'exam_date', 'start_time', 'end_time', 'hall_id', 'group_id', 'course_id');
        foreach ($examQuery->get() as $e) {
            $slots[] = [
                'id' => (int) $e->id,
                'kind' => 'exam',
                'day_of_week' => ArabicDay::of(Carbon::parse($e->exam_date)),
                'exam_date' => (string) $e->exam_date,
                'start_time' => substr((string) $e->start_time, 0, 8),
                'end_time' => substr((string) $e->end_time, 0, 8),
                'hall_id' => (int) $e->hall_id,
                'faculty_id' => 0,
                'group_id' => (int) $e->group_id,
                'course_id' => (int) $e->course_id,
            ];
        }

        return $slots;
    }

    private function buildPayload(string $type, array $input, string $day, array $ctx, array $existing): array
    {
        return [
            'type' => $type,
            'day_of_week' => $day,
            'start_time' => $input['start_time'],
            'end_time' => $input['end_time'],
            'hall_id' => $ctx['hall_id'],
            'faculty_id' => $ctx['faculty_id'],
            'group_id' => $ctx['group_id'],
            'course_id' => $ctx['course_id'],
            'hall_status' => $ctx['hall_status'],
            'hall_capacity' => $ctx['hall_capacity'],
            'group_student_count' => $ctx['group_student_count'],
            'eligible' => $ctx['eligible'],
            'existing' => $existing,
        ];
    }
}
