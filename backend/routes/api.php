<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnginePassthrough;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\GuardController;
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
    Route::post('/bookings/check', [BookingController::class, 'check'])->middleware('role:admin,dept');
    Route::post('/bookings', [BookingController::class, 'store'])->middleware('role:admin,dept');

    // ── الحارس ──
    Route::get('/status/now', [GuardController::class, 'nowHalls'])->middleware('role:guard,admin');
    Route::post('/halls/{hall}/lock', [GuardController::class, 'lock'])->middleware('role:guard,admin');
    Route::post('/halls/{hall}/unlock', [GuardController::class, 'unlock'])->middleware('role:guard,admin');
    Route::get('/locks', [GuardController::class, 'lockLog'])->middleware('role:admin');
});

// ── اللوحة والتقارير (غير مغلقة) — حاليًا للديمو؛ يمكن تقييدها لاحقًا ──
Route::get('/dashboard', [DashboardController::class, 'index']);
Route::get('/reports/utilization', [ReportController::class, 'utilization']);

// ── syst-engine passthrough (مطوّرون) ──
Route::get('/syst-engine/health', [EnginePassthrough::class, 'health']);
Route::post('/syst-engine/check', [EnginePassthrough::class, 'check']);
