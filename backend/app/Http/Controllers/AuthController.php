<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
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

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('syst')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => $user->role,
                'faculty_id' => $user->faculty_id,
                'batch_id' => $user->batch_id,
            ],
        ]);
    }

    // GET /api/me — بيانات الحساب الحالي
    public function me(Request $request)
    {
        $u = $request->user();

        return response()->json([
            'id' => $u->id,
            'name' => $u->name,
            'username' => $u->username,
            'role' => $u->role,
            'faculty_id' => $u->faculty_id,
            'batch_id' => $u->batch_id,
        ]);
    }

    // POST /api/logout
    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message_ar' => 'تم تسجيل الخروج.']);
    }
}
