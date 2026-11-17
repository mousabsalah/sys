<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Department;
use App\Models\FacultyEntity;
use App\Models\Hall;
use App\Models\Semester;
use App\Services\Audit\AuditLogger;
use App\Services\Deletion\DeleteGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * AdminMasterDataController — إدارة كليات/أقسام/قاعات/فصول دراسية بواجهة admin.
 * كل فعل متعدد الخطوات (تحقق ← حذف/أرشفة) يمر داخل معاملة واحدة + audit log.
 */
class AdminMasterDataController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    // ══════════════ الكليات ══════════════

    public function facultyStore(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('faculties', 'name')],
            'code' => ['required', 'string', 'max:20', 'unique:faculties,code'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $faculty = FacultyEntity::create($input);
        $this->audit->log($request, 'faculty.create', 'faculty', $faculty->id, ['code' => $faculty->code]);

        return response()->json(['ok' => true, 'faculty' => $faculty], Response::HTTP_CREATED);
    }

    public function facultyUpdate(Request $request, int $faculty): JsonResponse
    {
        $item = FacultyEntity::findOrFail($faculty);
        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('faculties', 'name')->ignore($item->id)],
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('faculties', 'code')->ignore($item->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $item->fill($input)->save();
        $this->audit->log($request, 'faculty.update', 'faculty', $item->id, $input);

        return response()->json(['ok' => true, 'faculty' => $item]);
    }

    public function facultyDelete(Request $request, int $faculty): JsonResponse
    {
        $item = FacultyEntity::findOrFail($faculty);

        return DB::transaction(function () use ($request, $item) {
            DeleteGuard::assertUnused('faculty', [
                'لا يمكن حذف كلية بها أقسام مرتبطة.' => Department::where('faculty_id', $item->id)->exists(),
                'لا يمكن حذف كلية بها فصول دراسية مرتبطة.' => Semester::where('faculty_id', $item->id)->exists(),
            ]);

            $item->delete();
            $this->audit->log($request, 'faculty.delete', 'faculty', $item->id, ['name' => $item->name]);

            return response()->json(['ok' => true]);
        });
    }

    // ══════════════ الأقسام ══════════════

    public function departmentStore(Request $request): JsonResponse
    {
        $input = $request->validate([
            'faculty_id' => ['required', 'integer', Rule::exists('faculties', 'id')],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20', 'unique:departments,code'],
        ]);

        if (Department::where('faculty_id', (int) $input['faculty_id'])->where('name', $input['name'])->exists()) {
            throw ValidationException::withMessages(['name' => ['يوجد قسم بهذا الاسم في نفس الكلية.']]);
        }

        $department = Department::create($input);
        $this->audit->log($request, 'department.create', 'department', $department->id, ['code' => $department->code]);

        return response()->json(['ok' => true, 'department' => $department], Response::HTTP_CREATED);
    }

    public function departmentUpdate(Request $request, int $department): JsonResponse
    {
        $item = Department::findOrFail($department);
        $input = $request->validate([
            'faculty_id' => ['sometimes', 'integer', Rule::exists('faculties', 'id')],
            'name' => ['sometimes', 'string', 'max:100'],
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('departments', 'code')->ignore($item->id)],
        ]);

        return DB::transaction(function () use ($request, $item, $input) {
            $newFacultyId = (int) ($input['faculty_id'] ?? $item->faculty_id);
            $newName = (string) ($input['name'] ?? $item->name);
            if (Department::where('faculty_id', $newFacultyId)->where('name', $newName)->where('id', '!=', $item->id)->exists()) {
                throw ValidationException::withMessages(['name' => ['يوجد قسم بهذا الاسم في نفس الكلية.']]);
            }

            $item->fill($input);

            // إذا انتقل القسم لكلية أخرى — ننقل faculty_id المخزّن على فصوله (نسخ denorm) للحفاظ على التسلسل
            if (array_key_exists('faculty_id', $input) && (int) $input['faculty_id'] !== (int) $item->getOriginal('faculty_id')) {
                Semester::where('department_id', $item->id)->update(['faculty_id' => (int) $input['faculty_id']]);
            }

            $item->save();
            $this->audit->log($request, 'department.update', 'department', $item->id, $input);

            return response()->json(['ok' => true, 'department' => $item]);
        });
    }

    public function departmentDelete(Request $request, int $department): JsonResponse
    {
        $item = Department::findOrFail($department);

        return DB::transaction(function () use ($request, $item) {
            DeleteGuard::assertUnused('department', [
                'لا يمكن حذف قسم به دفعات مرتبطة.' => Batch::where('department_id', $item->id)->exists(),
                'لا يمكن حذف قسم به فصول دراسية مرتبطة.' => Semester::where('department_id', $item->id)->exists(),
            ]);

            $item->delete();
            $this->audit->log($request, 'department.delete', 'department', $item->id, ['name' => $item->name]);

            return response()->json(['ok' => true]);
        });
    }

    // ══════════════ القاعات ══════════════

    public function hallStore(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:halls,name'],
            'capacity' => ['required', 'integer', 'min:1', 'max:2000'],
            'hall_type' => ['required', 'string', 'max:50', 'in:قاعة,معمل'],
            'status' => ['sometimes', Rule::in(['متاحة', 'صيانة', 'مغلقة'])],
            'equipment' => ['nullable', 'string', 'max:255'],
        ]);

        $hall = Hall::create($input);
        $this->audit->log($request, 'hall.create', 'hall', $hall->id, ['name' => $hall->name]);

        return response()->json(['ok' => true, 'hall' => $hall], Response::HTTP_CREATED);
    }

    public function hallUpdate(Request $request, int $hall): JsonResponse
    {
        $item = Hall::findOrFail($hall);
        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:50', Rule::unique('halls', 'name')->ignore($item->id)],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:2000'],
            'hall_type' => ['sometimes', 'string', 'max:50', 'in:قاعة,معمل'],
            'status' => ['sometimes', Rule::in(['متاحة', 'صيانة', 'مغلقة'])],
            'equipment' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $item->fill($input)->save();
        $this->audit->log($request, 'hall.update', 'hall', $item->id, $input);

        return response()->json(['ok' => true, 'hall' => $item]);
    }

    public function hallDelete(Request $request, int $hall): JsonResponse
    {
        $item = Hall::findOrFail($hall);

        return DB::transaction(function () use ($request, $item) {
            DeleteGuard::assertUnused('hall', [
                'لا يمكن حذف قاعة مرتبطة بمحاضرات.' => DB::table('timetables')->where('hall_id', $item->id)->exists(),
                'لا يمكن حذف قاعة مرتبطة بامتحانات.' => DB::table('exam_sessions')->where('hall_id', $item->id)->exists(),
                'لا يمكن حذف قاعة مرتبطة بحجوزات.' => DB::table('bookings')->where('hall_id', $item->id)->exists(),
            ]);

            $item->delete();
            $this->audit->log($request, 'hall.delete', 'hall', $item->id, ['name' => $item->name]);

            return response()->json(['ok' => true]);
        });
    }

    // ══════════════ الفصول الدراسية (semester) ══════════════

    /** منع المدير من أي كتابة على الفصول — الإدارة للمجلس؛ المدير يقرأ فقط */
    private function rejectSemesterWriteForAdmin()
    {
        return response()->json(['ok' => false, 'message_ar' => 'الفصول الدراسية تُدار عبر مجلس المدرسين — المدير يقرأ فقط.'], Response::HTTP_FORBIDDEN);
    }

    // POST /api/admin/semesters — إنشاء (مغلق: الإدارة للمجلس)
    // كتابة الفصول انتقلت لمجلس المدرسين (CouncilSemestersController) — المسار هنا للتوافق ويرجع 403
    public function semesterStore(Request $request): JsonResponse
    {
        return $this->rejectSemesterWriteForAdmin();
    }

    public function semesterUpdate(Request $request, int $semester): JsonResponse
    {
        return $this->rejectSemesterWriteForAdmin();
    }

    public function semesterSetDates(Request $request, int $semester): JsonResponse
    {
        return $this->rejectSemesterWriteForAdmin();
    }

    public function semesterArchive(Request $request, int $semester): JsonResponse
    {
        return $this->rejectSemesterWriteForAdmin();
    }

    public function semesterUnarchive(Request $request, int $semester): JsonResponse
    {
        return $this->rejectSemesterWriteForAdmin();
    }

    public function semesterDelete(Request $request, int $semester): JsonResponse
    {
        return $this->rejectSemesterWriteForAdmin();
    }

    // ══════════════ قوائم الفصول (شاملة البيانات) ══════════════

    public function semestersIndex(): JsonResponse
    {
        $items = Semester::with('faculty:id,name,code', 'department:id,name', 'batch:id,name,student_count')
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'faculty_id' => $s->faculty_id,
                'department_id' => $s->department_id,
                'batch_id' => $s->batch_id,
                'academic_term' => $s->academic_term,
                'start_date' => $s->start_date?->format('Y-m-d'),
                'end_date' => $s->end_date?->format('Y-m-d'),
                'archived_at' => $s->archived_at,
                'faculty' => $s->faculty?->name,
                'department' => $s->department?->name,
                'batch' => $s->batch?->name,
                'student_count' => $s->batch?->student_count,
            ]);

        return response()->json(['semesters' => $items, 'total' => $items->count()]);
    }
}
