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
