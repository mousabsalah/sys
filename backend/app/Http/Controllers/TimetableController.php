<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Timetable;
use App\Services\Access\FacultyScope;
use App\Services\Engine\ConflictChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TimetableController extends Controller
{
    public function __construct(private ConflictChecker $checker) {}

    // POST /api/timetables/check — تحقق فقط (لا كتابة)
    public function check(Request $request)
    {
        $input = $this->validatePayload($request);

        return response()->json($this->checker->checkTimetable($input));
    }

    // POST /api/timetables — حفظ بمعاملة وإعادة فحص تحت قفل
    public function store(Request $request)
    {
        $input = $this->validatePayload($request);
        $input['academic_term'] = $request->input('academic_term', '2026/1');

        $result = $this->checker->commitTimetable($input);

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message_ar' => $result['message_ar'] ?? 'تعارض هو تعذّر الحفظ',
                'verdict' => $result['verdict'] ?? null,
            ], 422);
        }

        return response()->json(['ok' => true, 'timetable' => $result['timetable']], 201);
    }

    /**
     * POST /api/timetables/bulk — اعتماد مسودة كاملة بمعاملة واحدة.
     * كل سطر يمر بنفس commitTimetable (قفل + Go check + R13).
     * أي تعارض في أي سطر ⇒ rollback كامل (لا يُحفظ شيء) — يرجع قائمة الأسطر المتعارضة.
     */
    public function bulkStore(Request $request)
    {
        $input = $request->validate([
            'assignments' => ['required', 'array', 'min:1', 'max:500'],
            'assignments.*.course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
            'assignments.*.faculty_id' => ['required', 'integer', Rule::exists('faculty', 'id')],
            'assignments.*.group_id' => ['required', 'integer', Rule::exists('groups', 'id')],
            'assignments.*.hall_id' => ['required', 'integer', Rule::exists('halls', 'id')],
            'assignments.*.day_of_week' => ['required', 'string', 'in:السبت,الأحد,الاثنين,الثلاثاء,الأربعاء,الخميس'],
            'assignments.*.start_time' => ['required', 'date_format:H:i:s'],
            'assignments.*.end_time' => ['required', 'date_format:H:i:s', 'after:start_time'],
            'assignments.*.academic_term' => ['sometimes', 'string', 'max:20'],
            'assignments.*.course_code' => ['sometimes', 'string', 'max:20'],
        ]);

        $user = auth()->user();
        foreach ($input['assignments'] as $i => $item) {
            if (! FacultyScope::assertGroupInScope($user, (int) $item['group_id'])) {
                return response()->json([
                    'ok' => false,
                    'message_ar' => FacultyScope::deniedMessage()." (السطر #{$i})",
                ], 403);
            }
        }

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
                        'academic_term' => $item['academic_term'] ?? '2026/1',
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

    // GET /api/timetables?by=group&id=3  (يشمل شعب الدفعات — مجلس/مدير يريان الكل، بما فيه غير المعتمد)
    public function index(Request $request)
    {
        $user = auth()->user();
        $by = $request->query('by', 'group'); // group|faculty|hall|batch
        $id = (int) $request->query('id', 0);
        $term = $request->query('term');

        $query = Timetable::query();

        if ($term) {
            $query->where('academic_term', $term);
        }

        // من يرى المعتمد فقط: غير مجلس/مدير — المسودات يرى فقط صاحبها والمدير
        $privileged = in_array($user?->role, ['council', 'admin'], true);
        if (! $privileged) {
            $query->where('status', 'approved');
        }

        // مجلس يرى الجامعة كلها — لا قيد نطاق
        if ($by === 'batch') {
            $groupIds = Group::where('batch_id', $id)->pluck('id')->all();
            $query->whereIn('group_id', $groupIds);
        } elseif ($by === 'hall') {
            $query->where('hall_id', $id);
        } elseif ($by === 'faculty') {
            $query->where('faculty_id', $id);
        } else {
            $query->where('group_id', $id);
        }

        $rows = $query->orderBy('day_of_week')->orderBy('start_time')->get();

        return response()->json($rows);
    }

    private function validatePayload(Request $request): array
    {
        $input = $request->validate([
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
            'faculty_id' => ['required', 'integer', Rule::exists('faculty', 'id')],
            'group_id' => ['required', 'integer', Rule::exists('groups', 'id')],
            'hall_id' => ['required', 'integer', Rule::exists('halls', 'id')],
            'day_of_week' => ['required', 'string', 'in:السبت,الأحد,الاثنين,الثلاثاء,الأربعاء,الخميس'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'end_time' => ['required', 'date_format:H:i:s', 'after:start_time'],
            'academic_term' => ['sometimes', 'string', 'max:20'],
        ]);

        $user = $request->user();
        if ($user && ! FacultyScope::assertGroupInScope($user, (int) $input['group_id'])) {
            abort(403, FacultyScope::deniedMessage());
        }

        return $input;
    }
}
