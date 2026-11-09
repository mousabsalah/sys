<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * RoleAuthMiddleware
 * يمنع Dًوورًا معيَّناً من المسارات. يُستدعى بنوع الدور المسموح، مثال: role:admin,dept.
 * يُفترض وجود authenticated user (auth:sanctum) مسبقًا.
 */
class RoleAuthMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json(['message_ar' => 'يجب تسجيل الدخول أولاً'], 401);
        }

        if (! in_array($user->role, $roles, true)) {
            return response()->json([
                'message_ar' => 'ليس لديك صلاحية الوصول إلى هذا الإجراء',
                'your_role' => $user->role,
            ], 403);
        }

        return $next($request);
    }
}
