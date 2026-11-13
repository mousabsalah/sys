<?php

namespace App\Http\Controllers;

use App\Models\ExamSession;
use App\Models\Group;
use App\Services\Access\FacultyScope;
use App\Services\Engine\ConflictChecker;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExamController extends Controller
{
    public function __construct(private ConflictChecker $checker) {}

    public function check(Request $request)
    {
        $input = $this->validatePayload($request);

        return response()->json($this->checker->checkExam($input));
    }

    public function store(Request $request)
    {
        $input = $this->validatePayload($request);
        $input['academic_term'] = $request->input('academic_term', '2026/1');

        $result = $this->checker->commitExam($input);

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message_ar' => $result['message_ar'] ?? 'تعارض مع التحديثات الجارية',
                'verdict' => $result['verdict'] ?? null,
            ], 422);
        }

        return response()->json(['ok' => true, 'exam' => $result['exam']], 201);
    }

    public function index(Request $request)
    {
        $user = auth()->user();
        $by = $request->query('by', 'group'); // group|course
        $id = (int) $request->query('id', 0);

        $query = ExamSession::query();

        // ── دور الدفعة: امتحانات شعبه فقط — لا تجاهل لبارامترات الطلب ──
        if ($user?->role === 'batch') {
            if (! $user->batch_id) {
                abort(403, 'هذا الحساب غير مرتبط بدفعة — تواصل مع مجلس المدرسين.');
            }
            $groupIds = Group::where('batch_id', (int) $user->batch_id)->pluck('id')->all();

            return $query->whereIn('group_id', $groupIds)
                ->where('status', '!=', 'pending')
                ->orderBy('exam_date')->orderBy('start_time')->get();
        }

        // غير مجلس/مدير يرى المعتمد فقط — المسودات للمجلس والمدير
        if (! in_array($user?->role, ['council', 'admin'], true)) {
            $query->where('status', 'approved');
        }

        switch ($by) {
            case 'course':
                $query->where('course_id', $id);
                break;
            case 'batch':
                $groupIds = Group::where('batch_id', $id)->pluck('id')->all();
                $query->whereIn('group_id', $groupIds);
                break;
            case 'group':
            default:
                $query->where('group_id', $id);
        }

        return $query->orderBy('exam_date')->orderBy('start_time')->get();
    }

    private function validatePayload(Request $request): array
    {
        $input = $request->validate([
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
            'group_id' => ['required', 'integer', Rule::exists('groups', 'id')],
            'hall_id' => ['required', 'integer', Rule::exists('halls', 'id')],
            'exam_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'end_time' => ['required', 'date_format:H:i:s', 'after:start_time'],
            'exam_type' => ['sometimes', 'in:اختبار قصير,ميدترم,فاينل'],
            'academic_term' => ['sometimes', 'string', 'max:20'],
        ]);

        $user = $request->user();
        if ($user && ! FacultyScope::assertGroupInScope($user, (int) $input['group_id'])) {
            abort(403, FacultyScope::deniedMessage());
        }

        return $input;
    }
}
