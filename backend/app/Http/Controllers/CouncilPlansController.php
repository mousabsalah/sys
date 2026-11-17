<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Group;
use App\Models\GroupCourse;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CouncilPlansController — «الخطط الدراسية» لدور مجلس المدرسين حصريًا.
 * لكل شعبة: اختيار المقررات + الحصص الأسبوعية (sessions_per_week) — تستخدمها «الاقتراح التلقائي» (CP-SAT).
 * مجموع الحصص موصى أن يبقى ≤ شبكة الفترات المتاحة (٦ أيام × ٣ فترات = 18).
 */
class CouncilPlansController extends Controller
{
    /**
     * GET /api/council/plans — كل الخطط الدراسية لكل شعبة + قائمة المقررات للمحرر.
     */
    public function plansIndex(): JsonResponse
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

        $courses = Course::orderBy('code')->get(['id', 'code', 'name', 'credit_hours'])
            ->map(fn (Course $c) => [
                'id' => (int) $c->id,
                'code' => (string) $c->code,
                'name' => (string) $c->name,
                'credit_hours' => (int) $c->credit_hours,
            ])->values()->all();

        return response()->json([
            'groups' => $groups,
            'plans' => (object) $byGroup,
            'courses' => $courses,
        ]);
    }

    /**
     * POST /api/council/plans/{group} — استبدال خطة الشعبة كاملة بمعاملة واحدة.
     * body: { items: [{ course_id, sessions_per_week }] }
     */
    public function plansStore(Request $request, int $group): JsonResponse
    {
        $input = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.course_id' => ['required', 'integer', 'distinct', Rule::exists('courses', 'id')],
            'items.*.sessions_per_week' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $groupModel = DB::transaction(function () use ($input, $group, $request) {
            // قفل صف الشعبة داخل المعاملة — خطتان من نفس الشعبة لا تندمجان تزامنيًا
            $groupModel = Group::lockForUpdate()->findOrFail($group);
            GroupCourse::where('group_id', $groupModel->id)->delete();
            foreach ($input['items'] as $item) {
                GroupCourse::create([
                    'group_id' => $groupModel->id,
                    'course_id' => (int) $item['course_id'],
                    'sessions_per_week' => (int) $item['sessions_per_week'],
                ]);
            }
            app(AuditLogger::class)->log($request, 'plan.replace', 'group', $groupModel->id, ['course_count' => count($input['items'])]);

            return $groupModel;
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
}
