<?php

namespace App\Http\Controllers;

use App\Services\Academic\ArabicDay;
use App\Services\Engine\ConflictChecker;
use App\Support\Defaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * TimetableController — جدول المحاضرات عبر «اقتراح تلقائي» حصريًا.
 * الإنشاء/التعديل اليدوي أُزيل من النظام؛ المسار الوحيد لدخول محاضرات جديدة هو:
 * المجلس يقترح (ProposeController) → «اعتماد الكل» (bulkStore، معاملة واحدة) → الأدمن يعتمد.
 * الحذف والأرشفة الجماعية في CouncilScheduleController.
 */
class TimetableController extends Controller
{
    public function __construct(private ConflictChecker $checker) {}

    /**
     * POST /api/timetables/bulk — اعتماد مسودة كاملة بمعاملة واحدة.
     * كل سطر يمر بنفس commitTimetable (قفل + Go check + R13).
     * أي تعارض في أي سطر ⇒ rollback كامل (لا يُحفظ شيء) — يرجع قائمة الأسطر المتعارضة.
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $input = $request->validate([
            'assignments' => ['required', 'array', 'min:1', 'max:500'],
            'assignments.*.course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
            'assignments.*.faculty_id' => ['required', 'integer', Rule::exists('faculty', 'id')],
            'assignments.*.group_id' => ['required', 'integer', Rule::exists('groups', 'id')],
            'assignments.*.hall_id' => ['required', 'integer', Rule::exists('halls', 'id')],
            'assignments.*.day_of_week' => ['required', 'string', 'in:'.implode(',', ArabicDay::orderedStudyDays())],
            'assignments.*.start_time' => ['required', 'date_format:H:i:s'],
            'assignments.*.end_time' => ['required', 'date_format:H:i:s', 'after:start_time'],
            'assignments.*.academic_term' => ['sometimes', 'string', 'max:20'],
            'assignments.*.course_code' => ['sometimes', 'string', 'max:20'],
        ]);

        $user = auth()->user();
        $conflicts = [];
        $created = 0;

        try {
            DB::transaction(function () use (&$conflicts, &$created, $input, $user) {
                foreach ($input['assignments'] as $i => $item) {
                    $result = $this->checker->commitTimetable([
                        'course_id' => (int) $item['course_id'],
                        'faculty_id' => (int) $item['faculty_id'],
                        'group_id' => (int) $item['group_id'],
                        'hall_id' => (int) $item['hall_id'],
                        'day_of_week' => $item['day_of_week'],
                        'start_time' => $item['start_time'],
                        'end_time' => $item['end_time'],
                        'academic_term' => $item['academic_term'] ?? Defaults::DEFAULT_TERM,
                        'user_id' => $user->id,
                    ]);

                    if (! ($result['ok'] ?? false)) {
                        $conflicts[] = [
                            'index' => $i,
                            'group_id' => (int) $item['group_id'],
                            'course_id' => (int) $item['course_id'],
                            'course_code' => $item['course_code'] ?? null,
                            'day' => $item['day_of_week'],
                            'start_time' => $item['start_time'],
                            'end_time' => $item['end_time'],
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
                    'message_ar' => 'وُجد تعارض — لم يُحفظ أي سطر (الاعتماد الكلي أو لا شيء). عدّل ثم حاول مجددًا.',
                    'created' => 0,
                    'conflicts' => $conflicts,
                ], 422);
            }
            throw $e;
        }

        return response()->json(['ok' => true, 'created' => $created], 201);
    }
}
