<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * CouncilCoursesController — المقررات من صلاحيات «مجلس المدرسين» حصريًا.
 * المدير يقرأ المقررات فقط (قوائم «الخطط الدراسية») — لا يضيف ولا يعدّل ولا يحذف.
 */
class CouncilCoursesController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    // GET /api/council/courses — كل المقررات (+ فلتر اختياري ?q= حسب الكود أو الاسم)
    public function index(Request $request)
    {
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 60));

        $query = Course::query()->orderBy('code');
        if ($q !== '') {
            // بارامتر مربوط — لا يمكن حقن SQL من هنا
            $query->where(function ($w) use ($q) {
                $w->where('code', 'like', '%'.$q.'%')
                    ->orWhere('name', 'like', '%'.$q.'%');
            });
        }

        $courses = $query
            ->get()
            ->map(fn (Course $c): array => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'credit_hours' => $c->credit_hours,
                'required_equipment' => $c->required_equipment,
                'timetables_count' => DB::table('timetables')->where('course_id', $c->id)->count(),
                'exams_count' => DB::table('exam_sessions')->where('course_id', $c->id)->count(),
                'lecturers_count' => DB::table('course_faculty')->where('course_id', $c->id)->count(),
                'plans_count' => DB::table('group_courses')->where('course_id', $c->id)->count(),
            ]);

        return response()->json(['courses' => $courses]);
    }

    // POST /api/council/courses — إضافة مقرر
    public function store(Request $request)
    {
        $input = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:courses,code'],
            'name' => ['required', 'string', 'max:150'],
            'credit_hours' => ['required', 'integer', 'min:1', 'max:30'],
            'required_equipment' => ['nullable', 'string', 'max:255'],
        ]);

        $course = Course::create($input);
        $this->audit->log($request, 'course.create', 'course', $course->id, ['code' => $course->code, 'name' => $course->name]);

        return response()->json(['ok' => true, 'course' => $course], Response::HTTP_CREATED);
    }

    // PUT /api/council/courses/{course} — تعديل مقرر
    public function update(Request $request, int $course)
    {
        $item = Course::findOrFail($course);
        $input = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:30', Rule::unique('courses', 'code')->ignore($item->id)],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'credit_hours' => ['sometimes', 'required', 'integer', 'min:1', 'max:30'],
            'required_equipment' => ['nullable', 'string', 'max:255'],
        ]);

        $item->fill($input)->save();
        $this->audit->log($request, 'course.update', 'course', $item->id, $input);

        return response()->json(['ok' => true, 'course' => $item->refresh()]);
    }

    // DELETE /api/council/courses/{course} — حذف محروس (لا حذف مع أي استخدام)
    public function destroy(Request $request, int $course)
    {
        $item = Course::findOrFail($course);

        $blockers = [
            'timetables' => DB::table('timetables')->where('course_id', $item->id)->exists(),
            'exam_sessions' => DB::table('exam_sessions')->where('course_id', $item->id)->exists(),
            'course_faculty' => DB::table('course_faculty')->where('course_id', $item->id)->exists(),
            'group_courses' => DB::table('group_courses')->where('course_id', $item->id)->exists(),
        ];
        if (in_array(true, $blockers, true)) {
            throw ValidationException::withMessages([
                'course' => ['لا يمكن حذف مقرر مستخدم (جداول/امتحانات/ربط بمدرسين/خطة شعبة) — للأرشفة استخدم حذفه بعد فك كل الروابط.'],
            ]);
        }

        $this->audit->log($request, 'course.delete', 'course', $item->id, ['code' => $item->code, 'name' => $item->name]);
        $item->delete();

        return response()->json(['ok' => true]);
    }
}
