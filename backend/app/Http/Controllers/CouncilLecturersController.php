<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FacultyEntity;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * CouncilLecturersController — إدارة المدرسين لدور «مجلس المدرسين» (council).
 * المجلس: يضيف مدرسين بدرجاتهم العلمية، يربطهم بأكثر من كلية (قام بإنشائها المدير)،
 * ويربطهم بالمواد التي يدرسونها (مدرس بأكثر من مادة، والعكس).
 */
class CouncilLecturersController extends Controller
{
    public const DEGREES = ['معيد', 'محاضر', 'أستاذ مساعد', 'أستاذ مشارك', 'أستاذ'];

    public function __construct(private AuditLogger $audit) {}

    // GET /api/council/lecturers — كل المدرسين مع كلياتهم ومقرراتهم
    public function index()
    {
        $lecturers = Faculty::with(['faculties:id,name,code', 'department:id,name', 'courses:id,code,name'])
            ->withCount('courses')
            ->orderBy('full_name')
            ->get();

        // ربط الحسابات — استعلام واحد بدل N+1
        $accounts = User::whereNotNull('lecturer_id')
            ->whereIn('lecturer_id', $lecturers->pluck('id'))
            ->get(['lecturer_id', 'username', 'is_active'])
            ->keyBy('lecturer_id');

        $lecturers = $lecturers
            ->map(fn (Faculty $f): array => [
                'id' => $f->id,
                'full_name' => $f->full_name,
                'degree' => $f->degree,
                'department_id' => $f->department_id,
                'department' => $f->department?->name,
                'email' => $f->email,
                'phone' => $f->phone,
                'username' => $accounts[$f->id]?->username,
                'account_active' => $accounts[$f->id]?->is_active,
                'faculties' => $f->faculties->map(fn (FacultyEntity $fac): array => ['id' => $fac->id, 'name' => $fac->name, 'code' => $fac->code])->values(),
                'courses' => $f->courses->map(fn (Course $c): array => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->values(),
                'courses_count' => $f->courses_count,
            ]);

        return response()->json([
            'degrees' => self::DEGREES,
            'faculties' => FacultyEntity::orderBy('id')->get(['id', 'name', 'code']),
            'departments' => Department::orderBy('id')->get(['id', 'name', 'faculty_id']),
            'lecturers' => $lecturers,
            'total' => $lecturers->count(),
        ]);
    }

    // POST /api/council/lecturers — إضافة مدرس بدرجته وربطه بكلية (أو أكثر)
    public function store(Request $request)
    {
        $input = $request->validate([
            'full_name' => ['required', 'string', 'max:100'],
            'degree' => ['required', Rule::in(self::DEGREES)],
            'email' => ['required', 'email', 'max:100', 'unique:faculty,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'faculty_ids' => ['required', 'array', 'min:1'],
            'faculty_ids.*' => ['integer', Rule::exists('faculties', 'id')],
            'username' => ['required', 'string', 'min:3', 'max:50', 'unique:users,username'],
            'password' => ['nullable', 'string', 'min:6', 'max:100'],
        ]);

        $plainPassword = $input['password'] ?? self::generatePassword(10);
        $accountGenerated = empty($input['password']);

        $lecturer = DB::transaction(function () use ($input, $request, $plainPassword, $accountGenerated) {
            $lecturer = Faculty::create([
                'full_name' => $input['full_name'],
                'degree' => $input['degree'],
                'email' => $input['email'],
                'phone' => $input['phone'] ?? null,
                'department_id' => $input['department_id'] ?? null,
            ]);
            $lecturer->faculties()->sync(array_map('intval', $input['faculty_ids']));

            // حساب المدرس — دائم عند الإنشاء (اسم المستخدم مطلوب، وكلمة المرور تُولَّد إن تُركت فارغة)
            $account = User::create([
                'name' => $lecturer->full_name,
                'username' => $input['username'],
                'email' => $lecturer->email,
                'password' => $plainPassword,
                'role' => 'lecturer',
                'faculty_id' => null,
                'batch_id' => null,
                'lecturer_id' => $lecturer->id,
                'is_active' => true,
            ]);

            $this->audit->log($request, 'lecturer.create', 'lecturer', $lecturer->id, [
                'degree' => $lecturer->degree,
                'faculty_ids' => $input['faculty_ids'],
                'account_username' => $account->username,
                'password_generated' => $accountGenerated,
            ]);

            return [$lecturer, $account];
        });

        [$lecturer, $account] = $lecturer;

        $payload = ['ok' => true, 'lecturer' => $lecturer->load(['faculties:id,name,code', 'courses:id,code,name'])];
        $payload['account'] = ['username' => $account->username];
        if ($accountGenerated) {
            $payload['account']['temporary_password'] = $plainPassword; // تُعرض مرة واحدة فقط
        }

        return response()->json($payload, Response::HTTP_CREATED);
    }

    // PUT /api/council/lecturers/{lecturer} — تعديل وربط الكليات (sync كامل)
    public function update(Request $request, int $lecturer)
    {
        $item = Faculty::findOrFail($lecturer);

        $input = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:100'],
            'degree' => ['sometimes', Rule::in(self::DEGREES)],
            'email' => ['sometimes', 'email', 'max:100', Rule::unique('faculty', 'email')->ignore($item->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'department_id' => ['sometimes', 'nullable', 'integer', Rule::exists('departments', 'id')],
            'faculty_ids' => ['sometimes', 'array', 'min:1'],
            'faculty_ids.*' => ['integer', Rule::exists('faculties', 'id')],
        ]);

        $updated = DB::transaction(function () use ($input, $item, $request) {
            $item->fill(collect($input)->except('faculty_ids')->toArray());
            $item->save();
            if (array_key_exists('faculty_ids', $input)) {
                $item->faculties()->sync(array_map('intval', $input['faculty_ids']));
            }
            $this->audit->log($request, 'lecturer.update', 'lecturer', $item->id, $input);

            return $item;
        });

        return response()->json(['ok' => true, 'lecturer' => $updated->load(['faculties:id,name,code', 'courses:id,code,name'])]);
    }

    // DELETE /api/council/lecturers/{lecturer} — حذف محروس (مثل القاعات: لا حذف مع ارتباط)
    public function destroy(Request $request, int $lecturer)
    {
        $item = Faculty::findOrFail($lecturer);

        return DB::transaction(function () use ($request, $item) {
            if (DB::table('timetables')->where('faculty_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['lecturer' => ['لا يمكن حذف مدرس له محاضرات مسندة.']]);
            }
            if (DB::table('course_faculty')->where('faculty_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['lecturer' => ['لا يمكن حذف مدرس مرتبط بمقررات — أزِل ربطه أولًا.']]);
            }

            $item->delete();
            // تعطيل حساب المدرس أيضًا — لا حساب يتيم بعد الحذف
            $account = User::where('lecturer_id', $item->id)->first();
            if ($account) {
                $account->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
                $account->tokens()->delete();
            }
            $this->audit->log($request, 'lecturer.delete', 'lecturer', $item->id, ['full_name' => $item->full_name, 'account_deactivated' => (bool) $account]);

            return response()->json(['ok' => true]);
        });
    }

    // GET /api/council/lecturers/{lecturer}/courses — مقررات المدرس + الكل (للاختيار)
    public function courses(int $lecturer)
    {
        $item = Faculty::with('courses:id,code,name')->findOrFail($lecturer);

        return response()->json([
            'lecturer' => ['id' => $item->id, 'full_name' => $item->full_name],
            'assigned' => $item->courses,
            'courses' => Course::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    // POST /api/council/lecturers/{lecturer}/courses/{course} — ربط مدرس بمقرر (أو أكثر)
    public function bindCourse(Request $request, int $lecturer, int $course)
    {
        $item = Faculty::findOrFail($lecturer);
        Course::findOrFail($course);

        DB::table('course_faculty')->insertOrIgnore(['course_id' => $course, 'faculty_id' => $item->id]);
        $this->audit->log($request, 'course.bind', 'lecturer', $item->id, ['course_id' => $course]);

        return response()->json(['ok' => true]);
    }

    // DELETE /api/council/lecturers/{lecturer}/courses/{course} — فك الربط (R13)
    public function unbindCourse(Request $request, int $lecturer, int $course)
    {
        $item = Faculty::findOrFail($lecturer);

        if (DB::table('timetables')->where('faculty_id', $item->id)->where('course_id', $course)->exists()) {
            throw ValidationException::withMessages([
                'course' => ['لا يمكن فك الربط — للمدرس محاضرات مسندة في هذا المقرر. عدّل المحاضرات أولًا.'],
            ]);
        }

        DB::table('course_faculty')->where('faculty_id', $item->id)->where('course_id', $course)->delete();
        $this->audit->log($request, 'course.unbind', 'lecturer', $item->id, ['course_id' => $course]);

        return response()->json(['ok' => true]);
    }

    // POST /api/council/lecturers/{lecturer}/deactivate — إيقاف حساب المدرس (لا حذف؛ من المجلس فقط)
    public function deactivate(Request $request, int $lecturer)
    {
        $lecturerModel = Faculty::findOrFail($lecturer);
        $account = User::where('lecturer_id', $lecturerModel->id)->first();
        if (! $account) {
            return response()->json(['ok' => false, 'message_ar' => 'لا يوجد حساب لهذا المدرس.'], 422);
        }
        if (! $account->is_active) {
            return response()->json(['ok' => true, 'username' => $account->username, 'already' => true]);
        }

        DB::transaction(function () use ($account, $request) {
            $revoked = $account->tokens()->count();
            $account->tokens()->delete();
            $account->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
            $this->audit->log($request, 'lecturer.deactivate', 'lecturer', $account->lecturer_id, ['username' => $account->username, 'tokens_revoked' => $revoked]);
        });

        return response()->json(['ok' => true, 'username' => $account->username]);
    }

    // POST /api/council/lecturers/{lecturer}/reactivate — إعادة تفعيل حساب المدرس
    public function reactivate(Request $request, int $lecturer)
    {
        $lecturerModel = Faculty::findOrFail($lecturer);
        $account = User::where('lecturer_id', $lecturerModel->id)->first();
        if (! $account) {
            return response()->json(['ok' => false, 'message_ar' => 'لا يوجد حساب لهذا المدرس.'], 422);
        }
        if ($account->is_active) {
            return response()->json(['ok' => true, 'username' => $account->username, 'already' => true]);
        }

        DB::transaction(function () use ($account, $request) {
            $account->forceFill(['is_active' => true, 'deactivated_at' => null])->save();
            $this->audit->log($request, 'lecturer.reactivate', 'lecturer', $account->lecturer_id, ['username' => $account->username]);
        });

        return response()->json(['ok' => true, 'username' => $account->username]);
    }

    // POST /api/council/lecturers/{lecturer}/reset-password — كلمة مرور مؤقتة (تُعاد مرة واحدة)
    public function resetPassword(Request $request, int $lecturer)
    {
        $lecturerModel = Faculty::findOrFail($lecturer);
        $account = User::where('lecturer_id', $lecturerModel->id)->first();
        if (! $account) {
            return response()->json(['ok' => false, 'message_ar' => 'لا يوجد حساب لهذا المدرس.'], 422);
        }

        $plain = self::generatePassword(10);

        DB::transaction(function () use ($account, $request, $plain, &$revoked) {
            $account->password = $plain;
            $account->save();
            $revoked = $account->tokens()->count();
            $account->tokens()->delete();
            $this->audit->log($request, 'lecturer.reset_password', 'lecturer', $account->lecturer_id, ['username' => $account->username, 'tokens_revoked' => $revoked]);
        });

        return response()->json([
            'ok' => true,
            'username' => $account->username,
            'temporary_password' => $plain,
            'message_ar' => 'كلمة المرور المؤقتة (انسيها الآن): '.$plain,
        ]);
    }

    /** كلمة مرور مؤقتة — 10 أحرف */
    private static function generatePassword(int $length): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789@$!';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }
}
