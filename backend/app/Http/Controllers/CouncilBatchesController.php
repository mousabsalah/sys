<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\User;
use App\Services\Audit\AuditLogger;
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

    // GET /api/council/batch-accounts — كل الدفعات + حسابها (إن وُجد)
    public function index()
    {
        $batches = DB::table('batches')
            ->leftJoin('departments', 'departments.id', '=', 'batches.department_id')
            ->orderBy('batches.id')
            ->get(['batches.id', 'batches.name', 'batches.student_count', 'departments.name AS department']);

        $accounts = DB::table('users')
            ->where('role', 'batch')
            ->get(['id', 'batch_id', 'username', 'is_active', 'last_login_at'])
            ->keyBy('batch_id');

        $rows = $batches->map(fn ($b) => [
            'id' => (int) $b->id,
            'name' => (string) $b->name,
            'student_count' => (int) $b->student_count,
            'department' => $b->department,
            'account' => isset($accounts[$b->id]) ? [
                'id' => (int) $accounts[$b->id]->id,
                'username' => (string) $accounts[$b->id]->username,
                'is_active' => (bool) $accounts[$b->id]->is_active,
                'last_login_at' => $accounts[$b->id]->last_login_at,
            ] : null,
        ])->values();

        return response()->json(['batches' => $rows]);
    }

    // POST /api/council/batch-accounts — إنشاء حساب دفعة (username إلزامي، كلمة المرور تُولَّد إن تُركت فارغة)
    public function store(Request $request)
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
        $plainPassword = $input['password'] ?? self::generatePassword(10);
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
    public function deactivate(Request $request, int $user)
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
    public function reactivate(Request $request, int $user)
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
    public function resetPassword(Request $request, int $user)
    {
        $account = $this->batchAccount($user);
        $plain = self::generatePassword(10);

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
