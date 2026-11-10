<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnginePassthrough;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\GuardController;
use App\Http\Controllers\HallsController;
use App\Http\Controllers\ProposeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TimetableController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
    API routes v1 — SYST
    الوصول عبر auth:sanctum + دور ضمن role middleware.
*/
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// ── الذكور: تسجيل الدخول/الخروج/ملفي ──
Route::post('/login', [AuthController::class, 'login']);

// ── الدخول: واجهة المتصفح — طلب GET على /api/login يُرجع توضيح JSON (لا صفحة Symfony 405) ──
Route::get('/login', function () {
    return response()->json([
        'ok' => false,
        'message_ar' => 'هذا المسار يقبل POST فقط — استخدم واجهة الويب http://127.0.0.1:5173 (مع npm run dev شغّال) لإدخال اسم المستخدم وكلمة المرور.',
        'hint' => 'واجهة React SPA على منفذ 5173 هي نقطة الدخول للنظام.',
    ], 405);
});
Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// ── الجداول ──
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/timetables', [TimetableController::class, 'index']);
    Route::post('/timetables/check', [TimetableController::class, 'check'])->middleware('role:admin,dept');
    Route::post('/timetables', [TimetableController::class, 'store'])->middleware('role:admin,dept');

    // ── الامتحانات ──
    Route::get('/exams', [ExamController::class, 'index']);
    Route::post('/exams/check', [ExamController::class, 'check'])->middleware('role:admin,dept');
    Route::post('/exams', [ExamController::class, 'store'])->middleware('role:admin,dept');

    // ── الحجوزات ──
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/halls/available', [HallsController::class, 'available']);
    Route::post('/bookings/check', [BookingController::class, 'check'])->middleware('role:admin,dept');
    Route::post('/bookings', [BookingController::class, 'store'])->middleware('role:admin,dept');
    Route::post('/timetables/bulk', [TimetableController::class, 'bulkStore'])->middleware('role:admin,dept');
    Route::post('/admin/propose-timetable', [ProposeController::class, 'store'])->middleware('role:admin,dept');
    Route::get('/admin/propose-timetable/context', [ProposeController::class, 'context'])->middleware('role:admin,dept');

    // ── الحارس ──
    Route::get('/status/now', [GuardController::class, 'nowHalls'])->middleware('role:guard,admin');
    Route::post('/halls/{hall}/lock', [GuardController::class, 'lock'])->middleware('role:guard,admin');
    Route::post('/halls/{hall}/unlock', [GuardController::class, 'unlock'])->middleware('role:guard,admin');
    Route::get('/locks', [GuardController::class, 'lockLog'])->middleware('role:admin');
});

// ── اللوحة والتقارير (غير مغلقة) — حاليًا للديمو؛ يمكن تقييدها لاحقًا ──
Route::get('/dashboard', [DashboardController::class, 'index']);
Route::get('/reports/utilization', [ReportController::class, 'utilization']);

// ── لوحة المدير: بيانات رئيسية للقراءة (admin فقط) ──
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/admin/master-data', [AdminController::class, 'masterData']);
    Route::get('/admin/plans', [AdminController::class, 'plansIndex']);
    Route::post('/admin/plans/{group}', [AdminController::class, 'plansStore']);
});

// ── syst-engine passthrough (مطوّرون) ──
Route::get('/syst-engine/health', [EnginePassthrough::class, 'health']);
Route::post('/syst-engine/check', [EnginePassthrough::class, 'check']);
