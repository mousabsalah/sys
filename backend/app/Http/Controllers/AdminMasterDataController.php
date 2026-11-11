<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Department;
use App\Models\FacultyEntity;
use App\Models\Hall;
use App\Models\Semester;
use App\Services\Audit\AuditLogger;
use App\Services\Backup\BackupGateway;
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
    public function __construct(private AuditLogger $audit, private BackupGateway $dumper) {}

    // ══════════════ الكليات ══════════════

    public function facultyStore(Request $request)
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

    public function facultyUpdate(Request $request, int $faculty)
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

    public function facultyDelete(Request $request, int $faculty)
    {
        $item = FacultyEntity::findOrFail($faculty);

        return DB::transaction(function () use ($request, $item) {
            if (Department::where('faculty_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['faculty' => ['لا يمكن حذف كلية بها أقسام مرتبطة.']]);
            }
            if (Semester::where('faculty_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['faculty' => ['لا يمكن حذف كلية بها فصول دراسية مرتبطة.']]);
            }

            $item->delete();
            $this->audit->log($request, 'faculty.delete', 'faculty', $item->id, ['name' => $item->name]);

            return response()->json(['ok' => true]);
        });
    }

    // ══════════════ الأقسام ══════════════

    public function departmentStore(Request $request)
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

    public function departmentUpdate(Request $request, int $department)
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

    public function departmentDelete(Request $request, int $department)
    {
        $item = Department::findOrFail($department);

        return DB::transaction(function () use ($request, $item) {
            if (Batch::where('department_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['department' => ['لا يمكن حذف قسم به دفعات مرتبطة.']]);
            }
            if (Semester::where('department_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['department' => ['لا يمكن حذف قسم به فصول دراسية مرتبطة.']]);
            }

            $item->delete();
            $this->audit->log($request, 'department.delete', 'department', $item->id, ['name' => $item->name]);

            return response()->json(['ok' => true]);
        });
    }

    // ══════════════ القاعات ══════════════

    public function hallStore(Request $request)
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

    public function hallUpdate(Request $request, int $hall)
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

    public function hallDelete(Request $request, int $hall)
    {
        $item = Hall::findOrFail($hall);

        return DB::transaction(function () use ($request, $item) {
            if (DB::table('timetables')->where('hall_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['hall' => ['لا يمكن حذف قاعة مرتبطة بمحاضرات.']]);
            }
            if (DB::table('exam_sessions')->where('hall_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['hall' => ['لا يمكن حذف قاعة مرتبطة بامتحانات.']]);
            }
            if (DB::table('bookings')->where('hall_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['hall' => ['لا يمكن حذف قاعة مرتبطة بحجوزات.']]);
            }

            $item->delete();
            $this->audit->log($request, 'hall.delete', 'hall', $item->id, ['name' => $item->name]);

            return response()->json(['ok' => true]);
        });
    }

    // ══════════════ الفصول الدراسية (semester) ══════════════

    public function semesterStore(Request $request)
    {
        $input = $request->validate([
            'faculty_id' => ['required', 'integer', Rule::exists('faculties', 'id')],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id')],
            'academic_term' => ['required', 'string', 'max:20'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $this->assertAcademicChain((int) $input['faculty_id'], (int) $input['department_id'], (int) $input['batch_id']);
        $this->assertAcademicTermUnique((int) $input['department_id'], (int) $input['batch_id'], $input['academic_term'], null);

        $semester = Semester::create($input);
        $this->audit->log($request, 'semester.create', 'semester', $semester->id, ['academic_term' => $semester->academic_term]);

        return response()->json(['ok' => true, 'semester' => $semester], Response::HTTP_CREATED);
    }

    public function semesterUpdate(Request $request, int $semester)
    {
        $item = Semester::findOrFail($semester);
        if ($item->archived_at) {
            return response()->json(['ok' => false, 'message_ar' => 'الفصل مؤرشف — السجل التاريخي مجمّد. فكّ الأرشفة أولًا للتعديل.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $input = $request->validate([
            'faculty_id' => ['sometimes', 'integer', Rule::exists('faculties', 'id')],
            'department_id' => ['sometimes', 'integer', Rule::exists('departments', 'id')],
            'batch_id' => ['sometimes', 'integer', Rule::exists('batches', 'id')],
            'academic_term' => ['sometimes', 'string', 'max:20'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $facultyId = (int) ($input['faculty_id'] ?? $item->faculty_id);
        $departmentId = (int) ($input['department_id'] ?? $item->department_id);
        $batchId = (int) ($input['batch_id'] ?? $item->batch_id);
        $term = (string) ($input['academic_term'] ?? $item->academic_term);

        $this->assertAcademicChain($facultyId, $departmentId, $batchId);
        $this->assertAcademicTermUnique($departmentId, $batchId, $term, $item->id);

        $item->fill($input)->save();
        $this->audit->log($request, 'semester.update', 'semester', $item->id, $input);

        return response()->json(['ok' => true, 'semester' => $item]);
    }

    public function semesterSetDates(Request $request, int $semester)
    {
        $item = Semester::findOrFail($semester);
        if ($item->archived_at) {
            return response()->json(['ok' => false, 'message_ar' => 'لا يمكن تعديل تواريخ فصل مؤرشف — السجل التاريخي مجمّد.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $input = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);
        $item->fill($input)->save();
        $this->audit->log($request, 'semester.set_dates', 'semester', $item->id, ['start_date' => $input['start_date'], 'end_date' => $input['end_date']]);

        return response()->json(['ok' => true, 'semester' => $item]);
    }

    public function semesterArchive(Request $request, int $semester)
    {
        $item = Semester::findOrFail($semester);
        if ($item->archived_at) {
            return response()->json(['ok' => false, 'message_ar' => 'الفصل مؤرشفة بالفعل.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // نسخة احتياطية تلقائية قبل الأرشفة (متطلب: تلقائي كل نهاية فصل) — خارج المعاملة حتى لا تطول
        $backupFile = $this->dumper->dump();
        if ($backupFile === null) {
            // فشل النسخ لا يمنع الأرشفة (تشغيلية) لكنه مسجّل دائمًا — لا فجوة صامتة
            $this->audit->log($request, 'backup.auto_failed', 'semester', $item->id, ['error' => 'mysqldump فشل — راجع اللوج']);
        }

        DB::transaction(function () use ($item, $request, $backupFile) {
            $item->forceFill(['archived_at' => now()])->save();
            $this->audit->log($request, 'semester.archive', 'semester', $item->id, ['academic_term' => $item->academic_term, 'backup' => $backupFile]);
        });

        return response()->json(['ok' => true, 'semester' => $item->refresh(), 'backup' => $backupFile]);
    }

    public function semesterUnarchive(Request $request, int $semester)
    {
        $item = Semester::findOrFail($semester);
        DB::transaction(function () use ($item, $request) {
            $item->forceFill(['archived_at' => null])->save();
            $this->audit->log($request, 'semester.unarchive', 'semester', $item->id, ['academic_term' => $item->academic_term]);
        });

        return response()->json(['ok' => true, 'semester' => $item]);
    }

    public function semesterDelete(Request $request, int $semester)
    {
        $item = Semester::findOrFail($semester);
        if ($item->archived_at) {
            return response()->json(['ok' => false, 'message_ar' => 'لا يمكن حذف فصل مؤرشف — فكّ الأرشفة أولًا ثم احذف إن لم يكن مرتبطًا.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return DB::transaction(function () use ($request, $item) {
            if (DB::table('timetables')->where('semester_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['semester' => ['لا يمكن حذف فصل مرتبط بمحاضرات.']]);
            }
            if (DB::table('exam_sessions')->where('semester_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['semester' => ['لا يمكن حذف فصل مرتبط بامتحانات.']]);
            }
            if (DB::table('bookings')->where('semester_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['semester' => ['لا يمكن حذف فصل مرتبط بحجوزات.']]);
            }
            $item->delete();
            $this->audit->log($request, 'semester.delete', 'semester', $item->id, ['academic_term' => $item->academic_term]);

            return response()->json(['ok' => true]);
        });
    }

    // ══════════════ قوائم الفصول (شاملة البيانات) ══════════════

    public function semestersIndex()
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

    /**
     * التسلسل الأكاديمي يجب أن يكون مترابطًا: الدفعة ← قسمها، والقسم ← كليته.
     * وإلا فالبيانات تُقبل صامتة — ثم تنكسر واجهة الأرشفة والاقتراح التلقائي لاحقًا.
     */
    private function assertAcademicChain(int $facultyId, int $departmentId, int $batchId): void
    {
        $department = Department::find($departmentId);
        if (! $department || (int) $department->faculty_id !== $facultyId) {
            throw ValidationException::withMessages(['department_id' => ['القسم لا ينتمي للكلية المختارة.']]);
        }
        $batch = Batch::find($batchId);
        if (! $batch || (int) $batch->department_id !== $departmentId) {
            throw ValidationException::withMessages(['batch_id' => ['الدفعة لا تنتمي للقسم المختار.']]);
        }
    }

    private function assertAcademicTermUnique(int $departmentId, int $batchId, string $academicTerm, ?int $excludeId): void
    {
        $query = DB::table('semesters')
            ->where('department_id', $departmentId)
            ->where('batch_id', $batchId)
            ->where('academic_term', $academicTerm);
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['academic_term' => ['يوجد فصل بهذا المسمى لنفس القسم والدفعة.']]);
        }
    }
}
