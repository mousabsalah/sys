<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Group;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TempPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * CouncilBatchesController — مجلس المدرسين: حسابات الدفعات (نفس دورة حسابات المدرسين).
 * المدير لا ينشئ/يرى/يلمس حسابات الدفعات إطلاقًا.
 */
class CouncilBatchesController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    // GET /api/council/batch-accounts — كل الدفعات + شعبها + حسابها (إن وُجد) + مرجعيات الإدارة
    public function index(): JsonResponse
    {
        $batches = DB::table('batches')
            ->leftJoin('departments', 'departments.id', '=', 'batches.department_id')
            ->leftJoin('programs', 'programs.id', '=', 'batches.program_id')
            ->whereNull('batches.deleted_at')
            ->orderBy('batches.id')
            ->get(['batches.id', 'batches.name', 'batches.student_count', 'batches.program_id', 'batches.department_id',
                'departments.name AS department', 'programs.name AS program']);

        $groups = DB::table('groups')
            ->whereIn('batch_id', $batches->pluck('id'))
            ->get(['id', 'batch_id', 'track', 'student_count'])
            ->groupBy('batch_id');

        $accounts = DB::table('users')
            ->where('role', 'batch')
            ->get(['id', 'batch_id', 'username', 'is_active', 'last_login_at'])
            ->keyBy('batch_id');

        $rows = $batches->map(fn ($b) => [
            'id' => (int) $b->id,
            'name' => (string) $b->name,
            'student_count' => (int) $b->student_count,
            'program_id' => (int) $b->program_id,
            'program' => $b->program,
            'department' => $b->department,
            'department_id' => (int) $b->department_id,
            'groups' => collect($groups->get((int) $b->id, []))->map(fn ($g) => [
                'id' => (int) $g->id,
                'track' => (string) $g->track,
                'student_count' => (int) $g->student_count,
            ])->values()->all(),
            'account' => isset($accounts[$b->id]) ? [
                'id' => (int) $accounts[$b->id]->id,
                'username' => (string) $accounts[$b->id]->username,
                'is_active' => (bool) $accounts[$b->id]->is_active,
                'last_login_at' => $accounts[$b->id]->last_login_at,
            ] : null,
        ])->values();

        return response()->json([
            'batches' => $rows,
            'departments' => DB::table('departments')->orderBy('id')->get(['id', 'name']),
            'programs' => DB::table('programs')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    // POST /api/council/programs — إضافة برنامج (للدفعات الجديدة إن لم يوجد مناسب)
    public function storeProgram(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $program = DB::table('programs')->insertGetId([
            'name' => $input['name'],
            'description' => $input['description'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->log($request, 'program.create', 'program', (int) $program, ['name' => $input['name']]);

        return response()->json(['ok' => true, 'program' => ['id' => (int) $program, 'name' => $input['name']]], Response::HTTP_CREATED);
    }

    // POST /api/council/batches — إنشاء دفعة + شعبها تلقائيًا (حصة بالنصف لكل مسار، والباقي لأول مسار)
    public function storeBatch(Request $request): JsonResponse
    {
        $departmentIds = DB::table('departments')->pluck('id')->all();
        $input = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'student_count' => ['required', 'integer', 'min:1', 'max:10000'],
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'tracks' => ['required', 'array', 'min:1'],
            'tracks.*' => ['string', Rule::in(['دبلوم', 'بكلاريوس'])],
        ]);

        $departmentId = $input['department_id'] ?? (count($departmentIds) === 1 ? $departmentIds[0] : null);
        if (! $departmentId) {
            return response()->json(['ok' => false, 'message_ar' => 'يوجد أكثر من قسم — اختر القسم للدفعة.'], 422);
        }

        $tracks = array_values(array_unique($input['tracks']));
        $total = (int) $input['student_count'];
        $count = count($tracks);
        // دبلوم أولًا يأخذ حصته كاملة + الباقي — ثم بكلاريوس (ترتيب عربي معلن)
        usort($tracks, fn ($a, $b) => ($a === 'دبلوم' ? 0 : 1) <=> ($b === 'دبلوم' ? 0 : 1));
        $shares = [];
        $base = intdiv($total, $count);
        $remainder = $total - $base * $count;
        foreach ($tracks as $i => $track) {
            $shares[$track] = $base + ($i === 0 ? $remainder : 0);
        }

        $batch = DB::transaction(function () use ($input, $request, $departmentId, $shares, $total) {
            $batch = Batch::create([
                'name' => $input['name'],
                'student_count' => $total,
                'department_id' => $departmentId,
                'program_id' => (int) $input['program_id'],
            ]);

            foreach ($shares as $track => $share) {
                Group::create([
                    'batch_id' => $batch->id,
                    'track' => $track,
                    'student_count' => $share,
                ]);
            }

            $this->audit->log($request, 'batch.create', 'batch', $batch->id, [
                'name' => $batch->name,
                'student_count' => $total,
                'tracks' => array_keys($shares),
                'program_id' => $batch->program_id,
            ]);

            return $batch;
        });

        return response()->json(['ok' => true, 'batch' => [
            'id' => $batch->id,
            'name' => $batch->name,
            'student_count' => $batch->student_count,
            'tracks' => array_keys($shares),
        ]], Response::HTTP_CREATED);
    }

    // PUT /api/council/batches/{batch} — تعديل الاسم وسعة الدفعة (لا تعديل للشعب هنا)
    public function updateBatch(Request $request, int $batch): JsonResponse
    {
        $item = Batch::findOrFail($batch);
        $input = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:50'],
            'student_count' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
        ]);

        $item->fill($input)->save();
        $this->audit->log($request, 'batch.update', 'batch', $item->id, $input);

        return response()->json(['ok' => true]);
    }

    // DELETE /api/council/batches/{batch} — حذف محروس (لا حذف مع أي استخدام). الشعب تنحذف معها (cascade)
    public function destroyBatch(Request $request, int $batch): JsonResponse
    {
        $item = Batch::findOrFail($batch);
        $groupIds = DB::table('groups')->where('batch_id', $item->id)->pluck('id');

        $usage = [
            'timetables' => DB::table('timetables')->whereIn('group_id', $groupIds)->count(),
            'exam_sessions' => DB::table('exam_sessions')->whereIn('group_id', $groupIds)->count(),
            'group_courses' => DB::table('group_courses')->whereIn('group_id', $groupIds)->count(),
            'accounts' => DB::table('users')->where('batch_id', $item->id)->count(),
            'semesters' => DB::table('semesters')->where('batch_id', $item->id)->count(),
        ];
        $used = array_filter($usage, fn ($n) => $n > 0);
        if ($used) {
            $parts = array_map(fn ($k, $n) => $n.' × '.$k, array_keys($used), array_values($used));

            return response()->json(['ok' => false, 'message_ar' => 'لا يمكن حذف الدفعة: مرتبطة بـ '.implode('، ', $parts).' — أزل الروابط أولًا.'], 422);
        }

        $this->audit->log($request, 'batch.delete', 'batch', $item->id, ['name' => $item->name, 'student_count' => $item->student_count]);
        // حذف حازم — DB cascade تحذف الشعب معها (لا يمكن الوصول هنا إلا مع صفر استخدام طرف شعبها)
        $item->forceDelete();

        return response()->json(['ok' => true]);
    }

    // POST /api/council/batch-accounts — إنشاء حساب دفعة (username إلزامي، كلمة المرور تُولَّد إن تُركت فارغة)
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id')],
            'username' => ['required', 'string', 'min:3', 'max:50', 'unique:users,username'],
            'password' => ['nullable', 'string', 'min:6', 'max:100'],
        ]);

        if (User::where('role', 'batch')->where('batch_id', $input['batch_id'])->exists()) {
            return response()->json(['ok' => false, 'message_ar' => 'هذه الدفعة تملك حسابًا بالفعل.'], 422);
        }

        $batch = Batch::findOrFail((int) $input['batch_id']);
        $plainPassword = $input['password'] ?? TempPassword::issue(10);
        $generated = empty($input['password']);

        $account = DB::transaction(function () use ($input, $request, $batch, $plainPassword, $generated) {
            $account = User::create([
                'name' => 'دفعة '.$batch->name,
                'username' => $input['username'],
                'email' => $input['username'].'@batch.syst.local',
                'password' => $plainPassword,
                'role' => 'batch',
                'faculty_id' => null,
                'batch_id' => $batch->id,
                'is_active' => true,
            ]);

            $this->audit->log($request, 'batch.account_create', 'user', $account->id, [
                'username' => $account->username,
                'batch_id' => $batch->id,
                'password_generated' => $generated,
            ]);

            return $account;
        });

        $payload = [
            'ok' => true,
            'account' => ['id' => $account->id, 'username' => $account->username, 'batch_id' => $batch->id],
        ];
        if ($generated) {
            $payload['account']['temporary_password'] = $plainPassword; // تُعرض مرة واحدة فقط
        }

        return response()->json($payload, Response::HTTP_CREATED);
    }

    // POST /api/council/batch-accounts/{user}/deactivate — إيقاف حساب الدفعة (لا حذف)
    public function deactivate(Request $request, int $user): JsonResponse
    {
        $account = $this->batchAccount($user);
        if (! $account->is_active) {
            return response()->json(['ok' => true, 'username' => $account->username, 'already' => true]);
        }

        DB::transaction(function () use ($account, $request) {
            $revoked = $account->tokens()->count();
            $account->tokens()->delete();
            $account->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
            $this->audit->log($request, 'batch.account_deactivate', 'user', $account->id, ['username' => $account->username, 'tokens_revoked' => $revoked]);
        });

        return response()->json(['ok' => true, 'username' => $account->username]);
    }

    // POST /api/council/batch-accounts/{user}/reactivate — إعادة تفعيل
    public function reactivate(Request $request, int $user): JsonResponse
    {
        $account = $this->batchAccount($user);
        if ($account->is_active) {
            return response()->json(['ok' => true, 'username' => $account->username, 'already' => true]);
        }

        DB::transaction(function () use ($account, $request) {
            $account->forceFill(['is_active' => true, 'deactivated_at' => null])->save();
            $this->audit->log($request, 'batch.account_reactivate', 'user', $account->id, ['username' => $account->username]);
        });

        return response()->json(['ok' => true, 'username' => $account->username]);
    }

    // POST /api/council/batch-accounts/{user}/reset-password — كلمة مرور مؤقتة تُعرض مرة واحدة
    public function resetPassword(Request $request, int $user): JsonResponse
    {
        $account = $this->batchAccount($user);
        $plain = TempPassword::issue(10);

        DB::transaction(function () use ($account, $request, $plain, &$revoked) {
            $account->password = $plain;
            $account->save();
            $revoked = $account->tokens()->count();
            $account->tokens()->delete();
            $this->audit->log($request, 'batch.account_reset_password', 'user', $account->id, ['username' => $account->username, 'tokens_revoked' => $revoked]);
        });

        return response()->json([
            'ok' => true,
            'username' => $account->username,
            'temporary_password' => $plain,
            'message_ar' => 'كلمة المرور المؤقتة (انسيها الآن): '.$plain,
        ]);
    }

    /** حساب دفعة فقط — 404/403 لأي حساب آخر */
    private function batchAccount(int $userId): User
    {
        return User::where('id', $userId)->where('role', 'batch')->firstOrFail();
    }
}
