<?php

namespace App\Services\Engine;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\CourseFaculty;
use App\Models\ExamSession;
use App\Models\Group;
use App\Models\GuardLock;
use App\Models\Hall;
use App\Models\Timetable;
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

        $existing = $this->existingSlots(hallId: $ctx['hall_id'], dayOfWeek: $day, examDate: null);

        $payload = $this->buildPayload('lecture', $input, $day, $ctx, $existing);

        return $this->engine->check($payload);
    }

    public function checkExam(array $input): array
    {
        $ctx = $this->resolveCommon($input);
        $examDate = $input['exam_date'] ?? throw new RuntimeException('exam_date مطلوب للامتحان');

        $day = $this->arabicWeekday(Carbon::parse($examDate));

        $existing = $this->existingSlots(hallId: $ctx['hall_id'], dayOfWeek: $day, examDate: $examDate);

        $payload = $this->buildPayload('exam', $input, $day, $ctx, $existing);
        $payload['exam_date'] = $examDate;

        return $this->engine->check($payload);
    }

    public function checkBooking(array $input): array
    {
        $ctx = $this->resolveCommon($input);
        $date = $input['date'] ?? throw new RuntimeException('date مطلوب للحجز');

        $day = $this->arabicWeekday(Carbon::parse($date));

        $existing = $this->existingSlots(hallId: $ctx['hall_id'], dayOfWeek: $day, examDate: $date);

        $payload = $this->buildPayload('booking', $input, $day, $ctx, $existing);
        $payload['exam_date'] = $date;

        return $this->engine->check($payload);
    }

    // تنفيذ الحفظ الفعلي داخل معاملة الكتابة (يُستدعى من Controller)
    public function commitTimetable(array $input): array
    {
        $ctx = $this->resolveCommon($input);
        $day = $this->dayOfWeek($input);

        return DB::transaction(function () use ($input, $day, $ctx) {
            // 🔒 قفل صف القاعة — يقطع السباقات بين أي محاولتين متزامنتين
            Hall::where('id', $ctx['hall_id'])->lockForUpdate()->firstOrFail();

            // إعادة فحص مع بيانات حديثة داخل المعاملة
            $existing = $this->existingSlots(hallId: $ctx['hall_id'], dayOfWeek: $day, examDate: null);
            $payload = $this->buildPayload('lecture', $input, $day, $ctx, $existing);
            $verdict = $this->engine->check($payload);

            if ($verdict['conflict']) {
                return ['ok' => false, 'verdict' => $verdict, 'message_ar' => 'تعارض مع التحديثات الجارية'];
            }

            $timetable = Timetable::create([
                'course_id' => $input['course_id'],
                'faculty_id' => $input['faculty_id'] ?? null,
                'group_id' => $input['group_id'],
                'hall_id' => $ctx['hall_id'],
                'day_of_week' => $day,
                'start_time' => $input['start_time'],
                'end_time' => $input['end_time'],
                'academic_term' => $input['academic_term'],
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
        $day = $this->arabicWeekday(Carbon::parse($examDate));

        return DB::transaction(function () use ($input, $examDate, $day, $ctx) {
            Hall::where('id', $ctx['hall_id'])->lockForUpdate()->firstOrFail();

            $existing = $this->existingSlots(hallId: $ctx['hall_id'], dayOfWeek: $day, examDate: $examDate);
            $payload = $this->buildPayload('exam', $input, $day, $ctx, $existing);
            $payload['exam_date'] = $examDate;
            $verdict = $this->engine->check($payload);

            if ($verdict['conflict']) {
                return ['ok' => false, 'verdict' => $verdict, 'message_ar' => 'تعارض — الامتحان تعذّر الحفظ'];
            }

            $exam = ExamSession::create([
                'course_id' => $input['course_id'],
                'group_id' => $input['group_id'],
                'hall_id' => $ctx['hall_id'],
                'exam_date' => $examDate,
                'start_time' => $input['start_time'],
                'end_time' => $input['end_time'],
                'exam_type' => $input['exam_type'] ?? 'فاينل',
                'academic_term' => $input['academic_term'],
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
        $day = $this->arabicWeekday(Carbon::parse($date));

        return DB::transaction(function () use ($input, $date, $day, $ctx) {
            Hall::where('id', $ctx['hall_id'])->lockForUpdate()->firstOrFail();

            $existing = $this->existingSlots(hallId: $ctx['hall_id'], dayOfWeek: $day, examDate: $date);
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
            ]);

            return ['ok' => true, 'booking' => $booking];
        });
    }

    // اللحظة الحالية — للحراس: القاعات الخالية الآن
    public function currentFreeHalls(): array
    {
        $now = now();
        $today = $this->arabicWeekday($now);
        $nowTime = $now->format('H:i:s');

        $busyHallIds = DB::table('timetables')
            ->where('day_of_week', $today)
            ->where('start_time', '<=', $nowTime)->where('end_time', '>', $nowTime)
            ->pluck('hall_id')
            ->merge(DB::table('exam_sessions')
                ->whereDate('exam_date', $now->toDateString())
                ->where('start_time', '<=', $nowTime)->where('end_time', '>', $nowTime)
                ->pluck('hall_id'))
            ->merge(DB::table('bookings')
                ->whereDate('date', $now->toDateString())
                ->where('start_time', '<=', $nowTime)->where('end_time', '>', $nowTime)
                ->where('status', 'confirmed')
                ->pluck('hall_id'))
            ->unique()->values();

        // VB: نفكر في سجلات القفل الأخيرة لكل قاعة في PHP (واضح وموثوق)
        $lockedIds = GuardLock::orderByDesc('action_at')->get()
            ->groupBy('hall_id')
            ->filter(fn ($locks) => $locks->first()->action === 'lock')
            ->keys()
            ->values();

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
        $group = Group::findOrFail($input['group_id'] ?? 0);
        $batch = Batch::findOrFail($group->batch_id);

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
            'program_id' => (int) $batch->program_id,
            'group_id' => (int) $input['group_id'],
            'group_student_count' => (int) $group->student_count,
            'batch_id' => (int) $group->batch_id,
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
            return $this->arabicWeekday(Carbon::parse($input['date']));
        }
        $date = $input['exam_date'] ?? throw new RuntimeException('اليوم الأسبوعي مطلوب');

        return $this->arabicWeekday(Carbon::parse($date));
    }

    private function arabicWeekday(Carbon $date): string
    {
        $map = [
            Carbon::SUNDAY => 'الأحد',
            Carbon::MONDAY => 'الاثنين',
            Carbon::TUESDAY => 'الثلاثاء',
            Carbon::WEDNESDAY => 'الأربعاء',
            Carbon::THURSDAY => 'الخميس',
            Carbon::FRIDAY => 'الجمعة',
            Carbon::SATURDAY => 'السبت',
        ];

        return $map[$date->dayOfWeek];
    }

    private function existingSlots(int $hallId, string $dayOfWeek, ?string $examDate): array
    {
        $slots = [];

        // الجداول
        foreach (Timetable::where('day_of_week', $dayOfWeek)->get() as $t) {
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

        // الحجوزات
        $bookings = Booking::whereDate('date', $examDate ?? date('Y-m-d'))->where('hall_id', $hallId)->get();
        if ($examDate === null) {
            $bookings = Booking::all();
        }
        foreach ($bookings as $b) {
            $slots[] = [
                'id' => (int) $b->id,
                'kind' => 'booking',
                'day_of_week' => $examDate ? $this->arabicWeekday(Carbon::parse($b->date)) : ($dayOfWeek ?: 'السبت'),
                'exam_date' => (string) $b->date,
                'start_time' => substr((string) $b->start_time, 0, 8),
                'end_time' => substr((string) $b->end_time, 0, 8),
                'hall_id' => (int) $b->hall_id,
                'faculty_id' => 0,
                'group_id' => 0,
                'course_id' => (int) ($b->course_id ?? 0),
            ];
        }

        // الامتحانات
        $examQuery = ExamSession::query();
        foreach ($examQuery->get() as $e) {
            $slots[] = [
                'id' => (int) $e->id,
                'kind' => 'exam',
                'day_of_week' => $this->arabicWeekday(Carbon::parse($e->exam_date)),
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
