<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Department;
use App\Models\Semester;
use App\Services\Audit\AuditLogger;
use App\Services\Backup\BackupGateway;
use App\Services\Deletion\DeleteGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * CouncilSemestersController — «بداية السنة الدراسية» لدور «مجلس المدرسين» فقط.
 * المدير يقرأ الفصول فقط — كل الكتابة (فتح السنة، إنشاء/تعديل/أرشفة/حذف) ملك المجلس.
 * السنة = سمسترين (الصيغة: «السنة/الفصل» مثل 2026/1 و2026/2) — والمجلس يحدد مدة كل سمستر بنفسه.
 */
class CouncilSemestersController extends Controller
{
    public function __construct(private AuditLogger $audit, private BackupGateway $dumper) {}

    // GET /api/council/semesters — كل الفصول + حساب حالة كل فصل
    public function index()
    {
        $today = now()->startOfDay();
        $items = Semester::with('faculty:id,name', 'department:id,name', 'batch:id,name,student_count')
            ->orderBy('id')
            ->get()
            ->map(function (Semester $s) use ($today): array {
                $state = 'upcoming';
                if ($s->archived_at) {
                    $state = 'archived';
                } elseif ($s->start_date && $s->end_date) {
                    $state = ($today->between($s->start_date, $s->end_date)) ? 'active' : ($today->lt($s->start_date) ? 'upcoming' : 'ended');
                } elseif ($s->start_date && $today->lt($s->start_date)) {
                    $state = 'upcoming';
                } elseif ($s->start_date) {
                    $state = 'active';
                } else {
                    $state = 'upcoming';
                }

                return [
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
                    'state' => $state,
                ];
            });

        $active = $items->firstWhere('state', 'active');

        return response()->json([
            'semesters' => $items->values(),
            'active_id' => $active ? $active['id'] : null,
            'total' => $items->count(),
        ]);
    }

    // POST /api/council/semesters/open-year — فتح السنة الدراسية: سمسترين متزامنين لكل دفعة محددة
    public function openYear(Request $request)
    {
        $input = $request->validate([
            'semesters' => ['required', 'array', 'min:1', 'max:2'],
            'semesters.*.term' => ['required', 'string', 'max:20'],
            'semesters.*.start_date' => ['required', 'date'],
            'semesters.*.end_date' => ['required', 'date'],
            'batch_ids' => ['nullable', 'array'],
            'batch_ids.*' => ['integer', Rule::exists('batches', 'id')],
        ], [
            'semesters.required' => 'حدد الفصلين الدراسيين للسنة.',
            'semesters.*.term.required' => 'صيغة الفصل مطلوبة (مثل 2026/1).',
            'semesters.*.start_date.required' => 'تاريخ بداية كل فصل مطلوب.',
            'semesters.*.end_date.required' => 'تاريخ نهاية كل فصل مطلوب.',
        ]);

        foreach ($input['semesters'] as $idx => $sem) {
            if (strtotime($sem['end_date']) < strtotime($sem['start_date'])) {
                $message = 'الفصل في السطر '.($idx + 1).': تاريخ النهاية قبل تاريخ البداية.';
                throw ValidationException::withMessages(['semesters' => [$message]]);
            }
        }

        $batches = $input['batch_ids']
            ? Batch::whereIn('id', $input['batch_ids'])->get()
            : Batch::all();

        if ($batches->isEmpty()) {
            return response()->json(['ok' => false, 'message_ar' => 'لا توجد دفعات — أنشئ الدفعات أولًا من «إدارة الدفعات».'], 422);
        }

        // تحقق مسبق من كل التعارضات قبل إنشاء أي شيء — فشل كامل أو عدم فشل
        $conflicts = [];
        foreach ($batches as $batch) {
            $this->departmentOf($batch);
            foreach ($input['semesters'] as $sem) {
                $exists = DB::table('semesters')
                    ->where('department_id', $batch->department_id)
                    ->where('batch_id', $batch->id)
                    ->where('academic_term', $sem['term'])
                    ->exists();
                if ($exists) {
                    $conflicts[] = "{$batch->name} · {$sem['term']}";
                }
            }
        }
        if ($conflicts) {
            return response()->json([
                'ok' => false,
                'message_ar' => 'توجد فصول بالفعل بهذه الصيغ: '.implode('، ', $conflicts).' — غيّر صيغة الفصل أولًا.',
            ], 422);
        }

        $created = DB::transaction(function () use ($input, $request, $batches) {
            $rows = [];
            foreach ($batches as $batch) {
                $department = $this->departmentOf($batch);
                foreach ($input['semesters'] as $sem) {
                    $semester = Semester::create([
                        'faculty_id' => $department->faculty_id,
                        'department_id' => $department->id,
                        'batch_id' => $batch->id,
                        'academic_term' => $sem['term'],
                        'start_date' => $sem['start_date'],
                        'end_date' => $sem['end_date'],
                    ]);
                    $rows[] = $semester;
                }
            }
            $this->audit->log($request, 'semester.open_year', 'semester', $rows[0]->id, [
                'semesters' => collect($input['semesters'])->pluck('term')->all(),
                'batches' => $batches->pluck('id')->all(),
                'count' => count($rows),
            ]);

            return $rows;
        });

        return response()->json(['ok' => true, 'created_count' => count($created), 'semesters' => $created], Response::HTTP_CREATED);
    }

    // POST /api/council/semesters — إضافة فصل واحد (حالة فردية استثنائية بعد فتح العام)
    public function store(Request $request)
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
        $this->assertTermUnique((int) $input['department_id'], (int) $input['batch_id'], $input['academic_term'], null);

        $semester = Semester::create($input);
        $this->audit->log($request, 'semester.create', 'semester', $semester->id, ['academic_term' => $semester->academic_term]);

        return response()->json(['ok' => true, 'semester' => $semester], Response::HTTP_CREATED);
    }

    // PUT /api/council/semesters/{semester} — تعديل تواريخ/صيغة (الفصل نفسه لا يتغير)
    public function update(Request $request, int $semester)
    {
        $item = Semester::findOrFail($semester);
        if ($item->archived_at) {
            return response()->json(['ok' => false, 'message_ar' => 'الفصل مؤرشف — فكّ الأرشفة أولًا.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $input = $request->validate([
            'faculty_id' => ['sometimes', 'required', 'integer', Rule::exists('faculties', 'id')],
            'department_id' => ['sometimes', 'required', 'integer', Rule::exists('departments', 'id')],
            'batch_id' => ['sometimes', 'required', 'integer', Rule::exists('batches', 'id')],
            'academic_term' => ['sometimes', 'required', 'string', 'max:20'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $facultyId = (int) ($input['faculty_id'] ?? $item->faculty_id);
        $departmentId = (int) ($input['department_id'] ?? $item->department_id);
        $batchId = (int) ($input['batch_id'] ?? $item->batch_id);
        $this->assertAcademicChain($facultyId, $departmentId, $batchId);
        $term = $input['academic_term'] ?? $item->academic_term;
        $this->assertTermUnique($departmentId, $batchId, $term, $item->id);

        $item->fill($input + ['faculty_id' => $facultyId, 'department_id' => $departmentId, 'batch_id' => $batchId])->save();
        $this->audit->log($request, 'semester.update', 'semester', $item->id, $input);

        return response()->json(['ok' => true, 'semester' => $item->refresh()]);
    }

    // POST /api/council/semesters/{semester}/dates — تعيين تواريخ الفصل فقط
    public function setDates(Request $request, int $semester)
    {
        $item = Semester::findOrFail($semester);
        if ($item->archived_at) {
            return response()->json(['ok' => false, 'message_ar' => 'الفصل مؤرشف — فكّ الأرشفة أولًا.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $input = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $item->forceFill(['start_date' => $input['start_date'] ?? null, 'end_date' => $input['end_date'] ?? null])->save();
        $this->audit->log($request, 'semester.dates', 'semester', $item->id, $input);

        return response()->json(['ok' => true, 'semester' => $item]);
    }

    // POST /api/council/semesters/{semester}/archive — مع نسخة احتياطية تلقائية (نفس سلوك الأدمن سابقًا)
    public function archive(Request $request, int $semester)
    {
        $item = Semester::findOrFail($semester);
        if ($item->archived_at) {
            return response()->json(['ok' => false, 'message_ar' => 'الفصل مؤرشف بالفعل.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // نسخة احتياطية تلقائية قبل الأرشفة — فشلها لا يمنع الأرشفة لكنه مسجّل
        $backupFile = $this->dumper->dump();
        if ($backupFile === null) {
            $this->audit->log($request, 'backup.auto_failed', 'semester', $item->id, ['error' => 'mysqldump فشل — راجع اللوج']);
        }

        DB::transaction(function () use ($item, $request, $backupFile) {
            $item->forceFill(['archived_at' => now()])->save();
            $this->audit->log($request, 'semester.archive', 'semester', $item->id, ['academic_term' => $item->academic_term, 'backup' => $backupFile]);
        });

        return response()->json(['ok' => true, 'semester' => $item->refresh(), 'backup' => $backupFile]);
    }

    // POST /api/council/semesters/{semester}/unarchive
    public function unarchive(Request $request, int $semester)
    {
        $item = Semester::findOrFail($semester);
        DB::transaction(function () use ($item, $request) {
            $item->forceFill(['archived_at' => null])->save();
            $this->audit->log($request, 'semester.unarchive', 'semester', $item->id, ['academic_term' => $item->academic_term]);
        });

        return response()->json(['ok' => true, 'semester' => $item]);
    }

    // DELETE /api/council/semesters/{semester} — محروس: لا حذف مع أي استخدام (محاضرات/امتحانات/حجوزات)
    public function destroy(Request $request, int $semester)
    {
        $item = Semester::findOrFail($semester);
        if ($item->archived_at) {
            return response()->json(['ok' => false, 'message_ar' => 'لا يمكن حذف فصل مؤرشف — فكّ الأرشفة أولًا.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return DB::transaction(function () use ($request, $item) {
            DeleteGuard::assertUnused('semester', [
                'لا يمكن حذف فصل مرتبط بمحاضرات.' => DB::table('timetables')->where('semester_id', $item->id)->exists(),
                'لا يمكن حذف فصل مرتبط بامتحانات.' => DB::table('exam_sessions')->where('semester_id', $item->id)->exists(),
                'لا يمكن حذف فصل مرتبط بحجوزات.' => DB::table('bookings')->where('semester_id', $item->id)->exists(),
            ]);
            $item->delete();
            $this->audit->log($request, 'semester.delete', 'semester', $item->id, ['academic_term' => $item->academic_term]);

            return response()->json(['ok' => true]);
        });
    }

    private function departmentOf(Batch $batch): Department
    {
        $department = Department::find($batch->department_id);
        if (! $department) {
            throw ValidationException::withMessages(['batch_id' => ['الدفعة «'.$batch->name.'» بلا قسم مرتبط.']]);
        }

        return $department;
    }

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

    private function assertTermUnique(int $departmentId, int $batchId, string $academicTerm, ?int $excludeId): void
    {
        $query = DB::table('semesters')
            ->where('department_id', $departmentId)
            ->where('batch_id', $batchId)
            ->where('academic_term', $academicTerm);
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['academic_term' => ['يوجد فصل بنفس الصيغة لهذه الدفعة بالفعل.']]);
        }
    }
}
