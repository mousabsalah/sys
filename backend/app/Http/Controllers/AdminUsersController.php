<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * AdminUsersController — إدارة كل المستخدمين (admin فقط).
 * الفعل يمر عبر transaction + audit log — لا حذف فعلي (تعطيل فقط).
 */
class AdminUsersController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    // GET /api/admin/users?q=&role=&status=active|deactivated|all&limit=
    public function index(Request $request)
    {
        $query = User::query()->orderBy('id');

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('username', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        $status = $request->query('status', 'all');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'deactivated') {
            $query->where('is_active', false);
        }

        $users = $query->limit((int) $request->query('limit', 200))->get();

        return response()->json([
            'total' => $users->count(),
            'users' => $users->map(fn ($u) => $this->userPayload($u)),
        ]);
    }

    // POST /api/admin/users — إضافة مستخدم
    public function store(Request $request)
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'max:100'],
            'role' => ['required', Rule::in(['admin', 'dept', 'guard', 'batch'])],
            'faculty_id' => ['nullable', 'integer', Rule::exists('faculties', 'id')],
            'batch_id' => ['nullable', 'integer', Rule::exists('batches', 'id')],
        ]);

        // اتساق المراجع: dept → faculty، batch → batch
        if ($input['role'] === 'dept' && ! $input['faculty_id']) {
            throw ValidationException::withMessages(['faculty_id' => ['اختار الكلية للقسم.']]);
        }
        if ($input['role'] === 'batch' && ! $input['batch_id']) {
            throw ValidationException::withMessages(['batch_id' => ['اختار الدفعة للحساب.']]);
        }

        $user = User::create($input + ['is_active' => true]);

        $this->audit->log($request, 'user.create', 'user', $user->id, [
            'role' => $user->role,
            'username' => $user->username,
        ]);

        return response()->json(['ok' => true, 'user' => $this->userPayload($user)], Response::HTTP_CREATED);
    }

    // PUT /api/admin/users/{user} — تعديل بيانات الحساب (لا يغيّر الدور، لا يحذف)
    public function update(Request $request, int $user)
    {
        $target = User::findOrFail($user);

        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            'faculty_id' => ['sometimes', 'nullable', 'integer', Rule::exists('faculties', 'id')],
            'batch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('batches', 'id')],
        ]);

        $target->fill($input)->save();

        $this->audit->log($request, 'user.update', 'user', $target->id, $input);

        return response()->json(['ok' => true, 'user' => $this->userPayload($target)]);
    }

    // POST /api/admin/users/{user}/deactivate — تعطيل (ليس حذفًا)
    public function deactivate(Request $request, int $user)
    {
        $target = User::findOrFail($user);
        $me = $request->user();

        if ($target->id === $me->id) {
            return response()->json(['ok' => false, 'message_ar' => 'لا يمكنك تعطيل حسابك أنت.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $target->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();

        $this->audit->log($request, 'user.deactivate', 'user', $target->id, ['username' => $target->username]);

        return response()->json(['ok' => true, 'user' => $this->userPayload($target)]);
    }

    // POST /api/admin/users/{user}/reactivate — تفعيل الحساب الموقوف
    public function reactivate(Request $request, int $user)
    {
        $target = User::findOrFail($user);
        $target->forceFill(['is_active' => true, 'deactivated_at' => null])->save();

        $this->audit->log($request, 'user.reactivate', 'user', $target->id, ['username' => $target->username]);

        return response()->json(['ok' => true, 'user' => $this->userPayload($target)]);
    }

    // POST /api/admin/users/{user}/reset-password — كلمة مرور مؤقتة (تُعاد مرة واحدة)
    public function resetPassword(Request $request, int $user)
    {
        $target = User::findOrFail($user);
        $plain = $this->generatePassword(10);

        // Laravel Hash + Model cast('password' => 'hashed') → تمرير الـplain سيُخزن مشفّرًا
        $target->password = $plain;
        $target->save();

        $this->audit->log($request, 'user.reset_password', 'user', $target->id, ['username' => $target->username]);

        return response()->json([
            'ok' => true,
            'username' => $target->username,
            'temporary_password' => $plain,
            'message_ar' => 'كلمة المرور المؤقتة (انسيها الآن): '.$plain,
        ]);
    }

    private function userPayload(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'username' => $u->username,
            'role' => $u->role,
            'faculty_id' => $u->faculty_id,
            'batch_id' => $u->batch_id,
            'is_active' => (bool) $u->is_active,
            'deactivated_at' => $u->deactivated_at,
            'last_login_at' => $u->last_login_at,
            'email' => $u->email,
        ];
    }

    private function generatePassword(int $length = 10): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789@#$%!';

        return implode('', array_map(fn () => $chars[random_int(0, strlen($chars) - 1)], range(1, $length)));
    }
}
