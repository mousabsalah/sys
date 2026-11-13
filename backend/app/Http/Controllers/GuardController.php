<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\ExamSession;
use App\Models\GuardLock;
use App\Models\Hall;
use App\Models\Timetable;
use App\Services\Academic\ArabicDay;
use App\Services\Engine\ConflictChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GuardController extends Controller
{
    public function __construct(private ConflictChecker $checker) {}

    // التوصيات الخالية الآن — للحرس
    public function nowHalls()
    {
        return response()->json($this->checker->currentFreeHalls());
    }

    // GET /api/guard/today — الحارس: القاعات الحرة الآن فقط (المحاضرات والامتحانات تخصم) + جدول اليوم كاملًا لكل قاعة حرة
    public function today()
    {
        $now = now();
        $todayStr = $now->toDateString();
        $todayArabic = ArabicDay::of($now);
        $nowTime = $now->format('H:i:s');

        // القاعات المشغولة الآن (ملغية لا تحجب) — الحجوزات لا تخصم: الحارس يتعامل مع المحاضرات والامتحانات فقط
        $busyHallIds = Timetable::notArchived()
            ->where('status', '!=', 'cancelled')
            ->where('day_of_week', $todayArabic)
            ->where('start_time', '<=', $nowTime)->where('end_time', '>', $nowTime)
            ->pluck('hall_id')
            ->merge(
                ExamSession::notArchived()
                    ->whereDate('exam_date', $todayStr)
                    ->where('status', '!=', 'cancelled')
                    ->where('start_time', '<=', $nowTime)->where('end_time', '>', $nowTime)
                    ->pluck('hall_id')
            )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $halls = Hall::orderBy('name')->get();
        $hallIds = $halls->pluck('id');

        $stateOf = fn (string $start, string $end): string => $nowTime >= substr($end, 0, 8) ? 'ended' : ($nowTime < substr($start, 0, 8) ? 'upcoming' : 'ongoing');

        $lectures = Timetable::notArchived()
            ->where('day_of_week', $todayArabic)
            ->whereIn('timetables.hall_id', $hallIds)
            ->leftJoin('courses', 'courses.id', '=', 'timetables.course_id')
            ->leftJoin('groups', 'groups.id', '=', 'timetables.group_id')
            ->leftJoin('batches', 'batches.id', '=', 'groups.batch_id')
            ->leftJoin('faculty', 'faculty.id', '=', 'timetables.faculty_id')
            ->select(
                'timetables.id',
                'timetables.hall_id',
                'timetables.start_time',
                'timetables.end_time',
                'timetables.status',
                'courses.code AS course_code',
                'courses.name AS course_name',
                'batches.name AS batch',
                'groups.track AS track',
                'faculty.full_name AS faculty_name',
            )
            ->orderBy('timetables.start_time')
            ->toBase()
            ->get()
            ->map(fn ($r) => [
                'kind' => 'lecture',
                'id' => (int) $r->id,
                'hall_id' => (int) $r->hall_id,
                'start_time' => (string) $r->start_time,
                'end_time' => (string) $r->end_time,
                'status' => (string) $r->status,
                'label' => 'محاضرة',
                'course_code' => $r->course_code,
                'course_name' => $r->course_name,
                'group' => trim(($r->batch ?? '').' — '.($r->track ?? '')),
                'with' => $r->faculty_name,
                'state' => $stateOf((string) $r->start_time, (string) $r->end_time),
            ]);

        $exams = ExamSession::notArchived()
            ->whereDate('exam_date', $todayStr)
            ->where('status', '!=', 'cancelled')
            ->whereIn('exam_sessions.hall_id', $hallIds)
            ->leftJoin('courses', 'courses.id', '=', 'exam_sessions.course_id')
            ->leftJoin('groups', 'groups.id', '=', 'exam_sessions.group_id')
            ->leftJoin('batches', 'batches.id', '=', 'groups.batch_id')
            ->select(
                'exam_sessions.id',
                'exam_sessions.hall_id',
                'exam_sessions.start_time',
                'exam_sessions.end_time',
                'exam_sessions.status',
                'exam_sessions.exam_type',
                'courses.code AS course_code',
                'courses.name AS course_name',
                'batches.name AS batch',
                'groups.track AS track',
            )
            ->orderBy('exam_sessions.start_time')
            ->toBase()
            ->get()
            ->map(fn ($r) => [
                'kind' => 'exam',
                'id' => (int) $r->id,
                'hall_id' => (int) $r->hall_id,
                'start_time' => (string) $r->start_time,
                'end_time' => (string) $r->end_time,
                'status' => (string) $r->status,
                'label' => 'امتحان'.($r->exam_type ? ' — '.$r->exam_type : ''),
                'course_code' => $r->course_code,
                'course_name' => $r->course_name,
                'group' => trim(($r->batch ?? '').' — '.($r->track ?? '')),
                'with' => null,
                'state' => $stateOf((string) $r->start_time, (string) $r->end_time),
            ]);

        $entriesByHall = $lectures->concat($exams)
            ->groupBy('hall_id')
            ->map(fn ($items) => $items->sortBy('start_time')->values());

        $free = $halls
            ->filter(fn (Hall $h) => ! in_array((int) $h->id, $busyHallIds, true))
            ->map(fn (Hall $h) => [
                'id' => (int) $h->id,
                'name' => $h->name,
                'hall_type' => $h->hall_type,
                'capacity' => (int) $h->capacity,
                'equipment' => $h->equipment,
                'status' => $h->status,
                'entries' => $entriesByHall->get((int) $h->id, collect())->values()->all(),
            ])
            ->values();

        return response()->json([
            'now' => $now->toDateTimeString(),
            'day' => $todayArabic,
            'date' => $todayStr,
            'total_halls' => $halls->count(),
            'busy_now' => count($busyHallIds),
            'free_now' => $free->count(),
            'halls' => $free,
        ]);
    }

    // قفل/فتح قاعة — حارس فقط + سجل
    public function lock(Request $request, int $hallId)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:255',
        ]);

        $hall = Hall::findOrFail($hallId);

        // لا يمكن قفل قاعة فيها محاضرة/حجز/امتحان الآن — المحاضرات تُفلتر باليوم الصحيح
        $now = now();
        $today = $now->format('H:i:s');
        $weekday = ArabicDay::of($now);
        $inLecture = Timetable::notArchived()
            ->where('hall_id', $hallId)
            ->where('day_of_week', $weekday)
            ->where('start_time', '<=', $today)->where('end_time', '>', $today)->exists();
        $inExam = ExamSession::notArchived()
            ->where('hall_id', $hallId)
            ->whereDate('exam_date', $now->toDateString())
            ->where('start_time', '<=', $today)->where('end_time', '>', $today)->exists();
        $inBooking = Booking::active()
            ->where('hall_id', $hallId)
            ->whereDate('date', $now->toDateString())
            ->where('start_time', '<=', $today)->where('end_time', '>', $today)->exists();

        if ($inLecture || $inExam || $inBooking) {
            return response()->json([
                'ok' => false,
                'message_ar' => 'لا يمكن القفل — توجد محاضرة/امتحان/حجز الآن',
            ], 409);
        }

        DB::transaction(function () use ($hall, $validated, $request) {
            GuardLock::create([
                'hall_id' => $hall->id,
                'user_id' => $request->user()?->id,
                'action' => 'lock',
                'action_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);
            $hall->status = 'مغلقة';
            $hall->save();
        });

        return response()->json(['ok' => true, 'hall_id' => $hallId, 'action' => 'lock']);
    }

    public function unlock(Request $request, int $hallId)
    {
        $hall = Hall::findOrFail($hallId);

        DB::transaction(function () use ($hall, $request) {
            GuardLock::create([
                'hall_id' => $hall->id,
                'user_id' => $request->user()?->id,
                'action' => 'unlock',
                'action_at' => now(),
                'notes' => $request->input('notes'),
            ]);
            // نعيد الحالة لـ«متاحة» فقط إذا كانت مغلقة من حارس — لا نمس «صيانة» أو «متاحة» التي عيّنها الأدمن
            if ($hall->status === 'مغلقة') {
                $hall->status = 'متاحة';
                $hall->save();
            }
        });

        return response()->json(['ok' => true, 'hall_id' => $hallId, 'action' => 'unlock']);
    }

    // عرض سجل القفل — مدير فقط
    public function lockLog(Request $request)
    {
        $rows = GuardLock::with('hall:id,name')->orderByDesc('action_at')->limit(200)->get();

        return response()->json($rows);
    }
}
