<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureUserIsActive — الحسابات الموقوفة لا تصل لأي مسار مصادق،
 * حتى لو كانت تملك توكنًا صالحًا (role أو بدونه). مصدر واحد للحقيقة.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && ! (bool) $user->is_active) {
            return response()->json(['message_ar' => 'الحساب موقوف — تواصل مع مدير النظام'], 403);
        }

        return $next($request);
    }
}
