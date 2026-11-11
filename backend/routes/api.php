<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminBackupController;
use App\Http\Controllers\AdminMasterDataController;
use App\Http\Controllers\AdminUsersController;
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
    API routes v1 â€” SYST
    ط§ظ„ظˆطµظˆظ„ ط¹ط¨ط± auth:sanctum + ط¯ظˆط± ط¶ظ…ظ† role middleware.
*/
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// â”€â”€ ط§ظ„ط°ظƒظˆط±: طھط³ط¬ظٹظ„ ط§ظ„ط¯ط®ظˆظ„/ط§ظ„ط®ط±ظˆط¬/ظ…ظ„ظپظٹ â”€â”€
Route::post('/login', [AuthController::class, 'login']);

// â”€â”€ ط§ظ„ط¯ط®ظˆظ„: ظˆط§ط¬ظ‡ط© ط§ظ„ظ…طھطµظپط­ â€” ط·ظ„ط¨ GET ط¹ظ„ظ‰ /api/login ظٹظڈط±ط¬ط¹ طھظˆط¶ظٹط­ JSON (ظ„ط§ طµظپط­ط© Symfony 405) â”€â”€
Route::get('/login', function () {
    return response()->json([
        'ok' => false,
        'message_ar' => 'ظ‡ط°ط§ ط§ظ„ظ…ط³ط§ط± ظٹظ‚ط¨ظ„ POST ظپظ‚ط· â€” ط§ط³طھط®ط¯ظ… ظˆط§ط¬ظ‡ط© ط§ظ„ظˆظٹط¨ http://127.0.0.1:5173 (ظ…ط¹ npm run dev ط´ط؛ظ‘ط§ظ„) ظ„ط¥ط¯ط®ط§ظ„ ط§ط³ظ… ط§ظ„ظ…ط³طھط®ط¯ظ… ظˆظƒظ„ظ…ط© ط§ظ„ظ…ط±ظˆط±.',
        'hint' => 'ظˆط§ط¬ظ‡ط© React SPA ط¹ظ„ظ‰ ظ…ظ†ظپط° 5173 ظ‡ظٹ ظ†ظ‚ط·ط© ط§ظ„ط¯ط®ظˆظ„ ظ„ظ„ظ†ط¸ط§ظ….',
    ], 405);
});
Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// â”€â”€ ط§ظ„ط¬ط¯ط§ظˆظ„ â”€â”€
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/timetables', [TimetableController::class, 'index']);
    Route::post('/timetables/check', [TimetableController::class, 'check'])->middleware('role:dept');
    Route::post('/timetables', [TimetableController::class, 'store'])->middleware('role:dept');

    // â”€â”€ ط§ظ„ط§ظ…طھط­ط§ظ†ط§طھ â”€â”€
    Route::get('/exams', [ExamController::class, 'index']);
    Route::post('/exams/check', [ExamController::class, 'check'])->middleware('role:dept');
    Route::post('/exams', [ExamController::class, 'store'])->middleware('role:dept');

    // â”€â”€ ط§ظ„ط­ط¬ظˆط²ط§طھ â”€â”€
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/halls/available', [HallsController::class, 'available']);
    Route::post('/bookings/check', [BookingController::class, 'check'])->middleware('role:admin,dept');
    Route::post('/bookings', [BookingController::class, 'store'])->middleware('role:admin,dept');
    Route::post('/timetables/bulk', [TimetableController::class, 'bulkStore'])->middleware('role:dept');
    Route::post('/admin/propose-timetable', [ProposeController::class, 'store'])->middleware('role:dept');
    Route::get('/admin/propose-timetable/context', [ProposeController::class, 'context'])->middleware('role:dept');

    // â”€â”€ ط§ظ„ط­ط§ط±ط³ â”€â”€
    Route::get('/status/now', [GuardController::class, 'nowHalls'])->middleware('role:guard,admin');
    Route::post('/halls/{hall}/lock', [GuardController::class, 'lock'])->middleware('role:guard,admin');
    Route::post('/halls/{hall}/unlock', [GuardController::class, 'unlock'])->middleware('role:guard,admin');
    Route::get('/locks', [GuardController::class, 'lockLog'])->middleware('role:admin');
});

// â”€â”€ ط§ظ„ظ„ظˆط­ط© ظˆط§ظ„طھظ‚ط§ط±ظٹط± (ظ…ظ‚ط§ط³ط© ظ„ظ„ظ…ظ†ط§ظ‚ط´ط©ط› ط¥ط«ط¨ط§طھ: ظ„ط§ ظ‚ط¨ظ„ طھط³ط¬ظٹظ„ ط§ظ„ط¯ط®ظˆظ„) â”€â”€
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/reports/utilization', [ReportController::class, 'utilization']);
});

// â”€â”€ ظ„ظˆط­ط© ط§ظ„ظ…ط¯ظٹط±: ط¥ط¯ط§ط±ط© ط§ظ„ظ…ط³طھط®ط¯ظ…ظٹظ† + ط¨ظٹط§ظ†ط§طھ ط±ط¦ظٹط³ظٹط© (admin ظپظ‚ط·) â”€â”€
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    // ط¥ط¯ط§ط±ط© ط§ظ„ظ…ط³طھط®ط¯ظ…ظٹظ†
    Route::get('/admin/users', [AdminUsersController::class, 'index']);
    Route::post('/admin/users', [AdminUsersController::class, 'store']);
    Route::put('/admin/users/{user}', [AdminUsersController::class, 'update']);
    Route::post('/admin/users/{user}/deactivate', [AdminUsersController::class, 'deactivate']);
    Route::post('/admin/users/{user}/reactivate', [AdminUsersController::class, 'reactivate']);
    Route::post('/admin/users/{user}/reset-password', [AdminUsersController::class, 'resetPassword']);

    // ط§ظ„ط¨ظٹط§ظ†ط§طھ ط§ظ„ط±ط¦ظٹط³ظٹط©
    Route::get('/admin/master-data', [AdminController::class, 'masterData']);
    Route::get('/admin/plans', [AdminController::class, 'plansIndex']);
    Route::post('/admin/plans/{group}', [AdminController::class, 'plansStore']);

    // ط¥ط¯ط§ط±ط© ط§ظ„ظƒظ„ظٹط§طھ ظˆط§ظ„ط£ظ‚ط³ط§ظ… ظˆط§ظ„ظ‚ط§ط¹ط§طھ ظˆط§ظ„ظپطµظˆظ„ ط§ظ„ط¯ط±ط§ط³ظٹط© (CRUD ظƒط§ظ…ظ„ + audit)
    Route::post('/admin/faculties', [AdminMasterDataController::class, 'facultyStore']);
    Route::put('/admin/faculties/{faculty}', [AdminMasterDataController::class, 'facultyUpdate']);
    Route::delete('/admin/faculties/{faculty}', [AdminMasterDataController::class, 'facultyDelete']);
    Route::post('/admin/departments', [AdminMasterDataController::class, 'departmentStore']);
    Route::put('/admin/departments/{department}', [AdminMasterDataController::class, 'departmentUpdate']);
    Route::delete('/admin/departments/{department}', [AdminMasterDataController::class, 'departmentDelete']);
    Route::post('/admin/halls', [AdminMasterDataController::class, 'hallStore']);
    Route::put('/admin/halls/{hall}', [AdminMasterDataController::class, 'hallUpdate']);
    Route::delete('/admin/halls/{hall}', [AdminMasterDataController::class, 'hallDelete']);
    Route::get('/admin/semesters', [AdminMasterDataController::class, 'semestersIndex']);
    Route::post('/admin/semesters', [AdminMasterDataController::class, 'semesterStore']);
    Route::put('/admin/semesters/{semester}', [AdminMasterDataController::class, 'semesterUpdate']);
    Route::post('/admin/semesters/{semester}/dates', [AdminMasterDataController::class, 'semesterSetDates']);
    Route::post('/admin/semesters/{semester}/archive', [AdminMasterDataController::class, 'semesterArchive']);
    Route::post('/admin/semesters/{semester}/unarchive', [AdminMasterDataController::class, 'semesterUnarchive']);
    Route::delete('/admin/semesters/{semester}', [AdminMasterDataController::class, 'semesterDelete']);

    // ظ„ظˆط­ط© ط§ظ„ط£ط¯ظ…ظ† ط§ظ„ط±ط³ظˆظ…ظٹط© + ط§ظ„ط£ط±ط´ظپط© ظ„ظ„ظ‚ط±ط§ط،ط© ظپظ‚ط·
    Route::get('/admin/dashboard-stats', [AdminDashboardController::class, 'dashboardStats']);
    Route::get('/admin/semesters/{semester}/archived', [AdminDashboardController::class, 'archivedSchedule']);

    // ط§ظ„ظ†ط³ط® ط§ظ„ط§ط­طھظٹط§ط·ظٹ (ط¥ظ†ط´ط§ط،/ظ‚ط§ط¦ظ…ط©/طھط­ظ…ظٹظ„ ظ…ط¨ط§ط´ط±)
    Route::post('/admin/backup/create', [AdminBackupController::class, 'create']);
    Route::get('/admin/backup/list', [AdminBackupController::class, 'list']);
    Route::get('/admin/backup/download/{file}', [AdminBackupController::class, 'download']);
});

// â”€â”€ syst-engine passthrough (ظ…ط·ظˆظ‘ط±ظˆظ†) â”€â”€
Route::get('/syst-engine/health', [EnginePassthrough::class, 'health']);
Route::post('/syst-engine/check', [EnginePassthrough::class, 'check']);

