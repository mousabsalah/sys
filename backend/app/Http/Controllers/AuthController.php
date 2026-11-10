<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // توحيد شكل المستخدم المُعاد للواجهة
    private function userPayload($u): array
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
        ];
    }

    // POST /api/login — الدخول بكلمة المرور المشفّرة
    public function login(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:50',
            'password' => 'required|string|min:6',
        ]);

        $user = User::where('username', $validated['username'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['بيانات الدخول غير صحيحة.'],
            ]);
        }

        if ($user->is_active === false) {
            throw ValidationException::withMessages([
                'username' => ['الحساب موقوف — تواصل مع إدارة النظام.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('syst')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    // GET /api/me — بيانات الحساب الحالي
    public function me(Request $request)
    {
        return response()->json($this->userPayload($request->user()));
    }

    // POST /api/logout
    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message_ar' => 'تم تسجيل الخروج.']);
    }
}
