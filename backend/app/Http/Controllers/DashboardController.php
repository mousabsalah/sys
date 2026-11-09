<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\Course;
use App\Models\ExamSession;
use App\Models\Hall;
use App\Models\Timetable;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $now = now();

        $kpis = [
            'halls_registered' => Hall::count(),
            'lectures_this_term' => Timetable::count(), // يفترض ترميز الفصل للقياس لاحقاً
            'bookings_today' => Booking::whereDate('date', $now->toDateString())->count(),
            'exams_this_week' => ExamSession::whereBetween('exam_date', [$now->startOfWeek()->toDateString(), $now->endOfWeek()->toDateString()])->count(),
            'courses_count' => Course::count(),
            'batches_count' => Batch::count(),
        ];

        $latestBookings = Booking::orderByDesc('created_at')->limit(5)->get();

        return response()->json([
            'kpis' => $kpis,
            'latest_bookings' => $latestBookings,
        ]);
    }
}
