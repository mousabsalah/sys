<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\Engine\ConflictChecker;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    public function __construct(private ConflictChecker $checker) {}

    // حجز يدوي بقاعة/تاريخ/وقت لمقرر أو نشاط
    public function store(Request $request)
    {
        $input = $this->validatePayload($request);
        $input['user_id'] = $request->user()->id ?? $request->input('user_id');

        $result = $this->checker->commitBooking($input);

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message_ar' => $result['message_ar'] ?? 'القاعة محجوزة',
                'verdict' => $result['verdict'] ?? null,
            ], 422);
        }

        return response()->json(['ok' => true, 'booking' => $result['booking']], 201);
    }

    // فحص تقديري متاح دون كتابة
    public function check(Request $request)
    {
        $input = $this->validatePayload($request);
        $input['user_id'] = $request->user()->id ?? $request->input('user_id');

        return response()->json($this->checker->checkBooking($input));
    }

    public function index(Request $request)
    {
        $by = $request->query('by'); // hall|date|null
        $id = $request->query('id');

        // بدون بارامترات: أحدث 100 حجز (قائمة الواجهة)
        if ($by === null || $id === null) {
            return Booking::orderByDesc('date')->orderByDesc('start_time')->limit(100)->get();
        }

        if ($by === 'date') {
            return Booking::whereDate('date', $id)->orderBy('start_time')->get();
        }

        return Booking::where('hall_id', (int) $id)->orderBy('date')->orderBy('start_time')->get();
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'hall_id' => ['required', 'integer', Rule::exists('halls', 'id')],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'end_time' => ['required', 'date_format:H:i:s', 'after:start_time'],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')],
            'group_id' => ['nullable', 'integer', Rule::exists('groups', 'id')],
            'activity_name' => ['nullable', 'string', 'max:100'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
        ]);
    }
}
