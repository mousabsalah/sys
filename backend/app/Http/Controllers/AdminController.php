<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseFaculty;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FacultyEntity;
use App\Models\Group;
use App\Models\GroupCourse;
use App\Models\Hall;
use App\Models\Period;
use App\Models\Program;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * AdminController — لوحة المدير للبيانات الرئيسية (كليات/أقسام/دفعات/قاعات/خطط...)
 * قراءة + إدارة الخطط الدراسية للشعب.
 */
class AdminController extends Controller
{
    /**
     * GET /api/admin/plans — كل الخطط الدراسية لكل شعبة (admin فقط)
     */
    public function plansIndex()
    {
        $groups = Group::with('batch:id,name')
            ->get(['id', 'batch_id', 'track', 'student_count']);

        $groupIds = $groups->pluck('id')->all();

        $items = DB::table('group_courses')
            ->join('courses', 'courses.id', '=', 'group_courses.course_id')
            ->whereIn('group_courses.group_id', $groupIds)
            ->select('group_courses.group_id', 'group_courses.course_id', 'group_courses.sessions_per_week', 'courses.code', 'courses.name', 'courses.credit_hours')
            ->get();

        $byGroup = [];
        foreach ($items as $item) {
            $byGroup[(int) $item->group_id][] = [
                'course_id' => (int) $item->course_id,
                'code' => (string) $item->code,
                'name' => (string) $item->name,
                'credit_hours' => (int) $item->credit_hours,
                'sessions_per_week' => (int) $item->sessions_per_week,
            ];
        }

        return response()->json([
            'groups' => $groups,
            'plans' => $byGroup,
        ]);
    }

    /**
     * POST /api/admin/plans/{group} — استبدال خطة الشعبة كاملة بمعاملة واحدة.
     * body: { items: [{ course_id, sessions_per_week }] }
     */
    public function plansStore(Request $request, int $group)
    {
        $input = $request->validate([
            'items' => ['required', 'array'],
            'items.*.course_id' => ['required', 'integer', 'distinct', Rule::exists('courses', 'id')],
            'items.*.sessions_per_week' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $groupModel = Group::findOrFail($group);

        DB::transaction(function () use ($input, $groupModel, $request) {
            GroupCourse::where('group_id', $groupModel->id)->delete();
            foreach ($input['items'] as $item) {
                GroupCourse::create([
                    'group_id' => $groupModel->id,
                    'course_id' => (int) $item['course_id'],
                    'sessions_per_week' => (int) $item['sessions_per_week'],
                ]);
            }
            app(AuditLogger::class)->log($request, 'plan.replace', 'group', $groupModel->id, ['course_count' => count($input['items'])]);
        });

        $plan = DB::table('group_courses')
            ->join('courses', 'courses.id', '=', 'group_courses.course_id')
            ->where('group_courses.group_id', $groupModel->id)
            ->select('group_courses.course_id', 'group_courses.sessions_per_week', 'courses.code', 'courses.name')
            ->get()
            ->map(fn ($item) => [
                'course_id' => (int) $item->course_id,
                'code' => (string) $item->code,
                'name' => (string) $item->name,
                'sessions_per_week' => (int) $item->sessions_per_week,
            ])->values()->all();

        return response()->json(['ok' => true, 'group_id' => $groupModel->id, 'plan' => $plan]);
    }

    public function masterData()
    {
        return response()->json([
            'faculties' => FacultyEntity::all(['id', 'name', 'code', 'description']),
            'departments' => Department::all(['id', 'faculty_id', 'name', 'code']),
            'programs' => Program::all(['id', 'name', 'description']),
            'periods' => Period::orderBy('program_id')->orderBy('day_of_week')->orderBy('sort_order')
                ->get(['id', 'program_id', 'day_of_week', 'start_time', 'end_time', 'label', 'sort_order']),
            'batches' => Batch::with('department:id,name', 'program:id,name')
                ->get(['id', 'name', 'student_count', 'department_id', 'program_id']),
            'groups' => Group::with('batch:id,name')
                ->get(['id', 'batch_id', 'track', 'student_count']),
            'halls' => Hall::all(['id', 'name', 'capacity', 'status', 'hall_type', 'equipment']),
            'courses' => Course::all(['id', 'code', 'name', 'credit_hours', 'required_equipment']),
            'faculty_members' => Faculty::with('department:id,name')
                ->get(['id', 'full_name', 'department_id', 'email', 'phone']),
            'course_assignments' => CourseFaculty::with('course:id,code,name', 'faculty:id,full_name')
                ->get(['id', 'course_id', 'faculty_id']),
        ]);
    }
}
