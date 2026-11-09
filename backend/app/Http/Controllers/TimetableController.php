<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Timetable;
use App\Services\Engine\ConflictChecker;
use Illuminate\Http\Request;
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

    // GET /api/timetables?by=group&id=3  (يشمل شعب الدفعات)
    public function index(Request $request)
    {
        $by = $request->query('by', 'group'); // group|faculty|hall
        $id = (int) $request->query('id', 0);
        $term = $request->query('term');

        $query = Timetable::query();

        if ($term) {
            $query->where('academic_term', $term);
        }

        switch ($by) {
            case 'hall':
                $query->where('hall_id', $id);
                break;
            case 'faculty':
                $query->where('faculty_id', $id);
                break;
            case 'batch':
            case 'group':
            default:
                $query->where('group_id', $id);
        }

        // سلسة شعب الدفعة معًا (دبلوم وبكلاريوس)
        if ($by === 'batch') {
            $groupIds = Group::where('batch_id', $id)->pluck('id')->all();
            $query = Timetable::whereIn('group_id', $groupIds);
            if ($term) {
                $query->where('academic_term', $term);
            }
        }

        return $query->orderBy('day_of_week')->orderBy('start_time')->get();
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
            'faculty_id' => ['required', 'integer', Rule::exists('faculty', 'id')],
            'group_id' => ['required', 'integer', Rule::exists('groups', 'id')],
            'hall_id' => ['required', 'integer', Rule::exists('halls', 'id')],
            'day_of_week' => ['required', 'string', 'in:السبت,الأحد,الاثنين,الثلاثاء,الأربعاء,الخميس'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'end_time' => ['required', 'date_format:H:i:s'],
            'academic_term' => ['sometimes', 'string', 'max:20'],
        ]);
    }
}
