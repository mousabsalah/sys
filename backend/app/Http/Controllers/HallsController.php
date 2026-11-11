<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\ExamSession;
use App\Models\Hall;
use App\Models\Timetable;
use App\Services\Academic\ArabicDay;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HallsController — «القاعات المتاحة» بفلتر تاريخي/زمني/سعة.
 * يجمع: حالة القاعة + سعة + عدم وجود أي محاضرة/امتحان/حجز خلال الفترة + عدم كونها مقفولة بالحارس (آخر إجراء = قفل).
 */
class HallsController extends Controller
{
    public function available(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'from' => 'required|date_format:H:i:s',
            'to' => 'required|date_format:H:i:s',
            'min_capacity' => 'sometimes|integer|min:1',
        ]);

        $from = $validated['from'];
        $to = $validated['to'];
        $minCapacity = (int) ($validated['min_capacity'] ?? 0);
        $date = $validated['date'];

        $dayArabic = ArabicDay::of(Carbon::parse($date));

        $busyIds = collect()
            ->merge(Timetable::notArchived()->where('day_of_week', $dayArabic)
                ->where('start_time', '<', $to)
                ->where('end_time', '>', $from)
                ->pluck('hall_id'))
            ->merge(ExamSession::notArchived()->whereDate('exam_date', $date)
                ->where('start_time', '<', $to)
                ->where('end_time', '>', $from)
                ->pluck('hall_id'))
            ->merge(Booking::active()
                ->whereDate('date', $date)
                ->where('start_time', '<', $to)
                ->where('end_time', '>', $from)
                ->pluck('hall_id'))
            ->unique()
            ->values();

        // قاعات مقفولة نشطًا (آخر إجراء = قفل) — محسوب في PHP
        $latestLocks = DB::table('guard_locks')
            ->select('hall_id', DB::raw('MAX(action_at) as last_at'))
            ->groupBy('hall_id')
            ->get();

        $lockedIds = collect();
        foreach ($latestLocks as $row) {
            $latest = DB::table('guard_locks')
                ->where('hall_id', $row->hall_id)
                ->where('action_at', $row->last_at)
                ->value('action');
            if ($latest === 'lock') {
                $lockedIds->push((int) $row->hall_id);
            }
        }

        $halls = Hall::where('status', 'متاحة')
            ->when($minCapacity > 0, fn ($q) => $q->where('capacity', '>=', $minCapacity))
            ->whereNotIn('id', $busyIds)
            ->whereNotIn('id', $lockedIds)
            ->orderBy('capacity')
            ->get(['id', 'name', 'capacity', 'hall_type', 'equipment']);

        return response()->json([
            'query' => ['date' => $date, 'from' => $from, 'to' => $to, 'min_capacity' => $minCapacity, 'day' => $dayArabic],
            'halls' => $halls,
            'total' => $halls->count(),
        ]);
    }
}
