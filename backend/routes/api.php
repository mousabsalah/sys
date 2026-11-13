<?php

use App\Http\Controllers\AdminApprovalsController;
use App\Http\Controllers\AdminBackupController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminMasterDataController;
use App\Http\Controllers\AdminUsersController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CouncilBatchesController;
use App\Http\Controllers\CouncilLecturersController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnginePassthrough;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\GuardController;
use App\Http\Controllers\HallsController;
use App\Http\Controllers\LecturerController;
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
})->middleware(['auth:sanctum', 'active']);

// ── تسجيل الدخول/الخروج/ملفي (الدخول: 5 محاولات/دقيقة لكل IP) ──
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1,login');

// ── الدخول: واجهة المتصفح — طلب GET على /api/login يُرجع توضيح JSON (لا صفحة Symfony 405) ──
Route::get('/login', function () {
    return response()->json([
        'ok' => false,
        'message_ar' => 'هذا المسار يقبل POST فقط — استخدم واجهة الويب http://127.0.0.1:5173 (مع npm run dev شغّال) لإدخال اسم المستخدم وكلمة المرور.',
        'hint' => 'واجهة React SPA على منفذ 5173 هي نقطة الدخول للنظام.',
    ], 405);
});
Route::get('/me', [AuthController::class, 'me'])->middleware(['auth:sanctum', 'active']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum', 'active']);

// ── الجداول ──
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/timetables', [TimetableController::class, 'index']);
    Route::post('/timetables/check', [TimetableController::class, 'check'])->middleware('role:council');
    Route::post('/timetables', [TimetableController::class, 'store'])->middleware('role:council');

    // ── الامتحانات ──
    Route::get('/exams', [ExamController::class, 'index']);
    Route::post('/exams/check', [ExamController::class, 'check'])->middleware('role:council');
    Route::post('/exams', [ExamController::class, 'store'])->middleware('role:council');

    // ── الحجوزات ──
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/halls/available', [HallsController::class, 'available']);
    Route::post('/bookings/check', [BookingController::class, 'check'])->middleware('role:admin,council,lecturer');
    Route::post('/bookings', [BookingController::class, 'store'])->middleware('role:admin,council,lecturer');
    Route::post('/timetables/bulk', [TimetableController::class, 'bulkStore'])->middleware('role:council');
    Route::post('/admin/propose-timetable', [ProposeController::class, 'store'])->middleware('role:council');
    Route::get('/admin/propose-timetable/context', [ProposeController::class, 'context'])->middleware('role:council');

    // ── الحارس ──
    Route::get('/status/now', [GuardController::class, 'nowHalls'])->middleware('role:guard,admin');
    Route::get('/guard/today', [GuardController::class, 'today'])->middleware('role:guard,admin');
    Route::post('/halls/{hall}/lock', [GuardController::class, 'lock'])->middleware('role:guard,admin');
    Route::post('/halls/{hall}/unlock', [GuardController::class, 'unlock'])->middleware('role:guard,admin');
    Route::get('/locks', [GuardController::class, 'lockLog'])->middleware('role:admin');
});

// ── مجلس المدرسين: إدارة حسابات الدفعات (دورة كاملة — المدير ممنوع منها) ──
Route::middleware(['auth:sanctum', 'active', 'role:council'])->group(function () {
    Route::get('/council/batch-accounts', [CouncilBatchesController::class, 'index']);
    Route::post('/council/batch-accounts', [CouncilBatchesController::class, 'store']);
    Route::post('/council/batch-accounts/{user}/deactivate', [CouncilBatchesController::class, 'deactivate']);
    Route::post('/council/batch-accounts/{user}/reactivate', [CouncilBatchesController::class, 'reactivate']);
    Route::post('/council/batch-accounts/{user}/reset-password', [CouncilBatchesController::class, 'resetPassword'])->middleware('throttle:10,1,batch-reset');
});

// ── دور الدفعة: جدوله + جدول امتحاناته + إشعارات تغييرات محاضراته — محصور بدفعته عبر users.batch_id ──
Route::middleware(['auth:sanctum', 'active', 'role:batch'])->group(function () {
    Route::get('/batch/timetables', [BatchController::class, 'timetables']);
    Route::get('/batch/exams', [BatchController::class, 'exams']);
    Route::get('/batch/notifications', [BatchController::class, 'notifications']);
    Route::get('/batch/notifications/count', [BatchController::class, 'notificationsCount']);
    Route::post('/batch/notifications/read', [BatchController::class, 'markNotificationsRead']);
});

// ── مجلس المدرسين: إدارة المدرسين + ربطهم بالكليات والمقررات ──
Route::middleware(['auth:sanctum', 'active', 'role:council'])->group(function () {
    Route::get('/council/lecturers', [CouncilLecturersController::class, 'index']);
    Route::post('/council/lecturers/{lecturer}/deactivate', [CouncilLecturersController::class, 'deactivate']);
    Route::post('/council/lecturers/{lecturer}/reactivate', [CouncilLecturersController::class, 'reactivate']);
    Route::post('/council/lecturers/{lecturer}/reset-password', [CouncilLecturersController::class, 'resetPassword'])->middleware('throttle:10,1,lecturer-reset');
    Route::post('/council/lecturers', [CouncilLecturersController::class, 'store']);
    Route::put('/council/lecturers/{lecturer}', [CouncilLecturersController::class, 'update']);
    Route::delete('/council/lecturers/{lecturer}', [CouncilLecturersController::class, 'destroy']);
    Route::get('/council/lecturers/{lecturer}/courses', [CouncilLecturersController::class, 'courses']);
    Route::post('/council/lecturers/{lecturer}/courses/{course}', [CouncilLecturersController::class, 'bindCourse'])->middleware('throttle:30,1,bind');
    Route::delete('/council/lecturers/{lecturer}/courses/{course}', [CouncilLecturersController::class, 'unbindCourse'])->middleware('throttle:30,1,bind');
});

// ── دور المدرس: جدول شعبه + إلغاء منطقيا + طلبات البدلاء + حجز القاعات ──
Route::middleware(['auth:sanctum', 'active', 'role:lecturer'])->group(function () {
    Route::get('/lecturer/groups', [LecturerController::class, 'myGroups']);
    Route::get('/lecturer/timetables', [LecturerController::class, 'timetables']);
    Route::post('/lecturer/timetables/{timetable}/cancel', [LecturerController::class, 'cancelLecture']);
    Route::post('/lecturer/timetables/{timetable}/uncancel', [LecturerController::class, 'uncancelLecture']);
    Route::get('/lecturer/lecturers', [LecturerController::class, 'groupLecturers']);
    Route::post('/lecturer/timetables/{timetable}/substitute-request', [LecturerController::class, 'requestSubstitute'])->middleware('throttle:20,1,sub-req');
    Route::get('/lecturer/substitute-requests', [LecturerController::class, 'substituteRequests']);
    Route::post('/lecturer/substitute-requests/{request_}/respond', [LecturerController::class, 'respondSubstitute']);
    Route::post('/lecturer/substitute-requests/{request_}/cancel', [LecturerController::class, 'cancelSubstitute']);
});

// ── اللوحة والتقارير (مقاسة للمناقشة؛ إثبات: لا قبل تسجيل الدخول) ──
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/reports/utilization', [ReportController::class, 'utilization']);
});

// ── لوحة المدير: إدارة المستخدمين + بيانات رئيسية (admin فقط) ──
Route::middleware(['auth:sanctum', 'active', 'role:admin'])->group(function () {
    // إدارة المستخدمين
    Route::get('/admin/users', [AdminUsersController::class, 'index']);
    Route::post('/admin/users', [AdminUsersController::class, 'store']);
    Route::put('/admin/users/{user}', [AdminUsersController::class, 'update']);
    Route::post('/admin/users/{user}/deactivate', [AdminUsersController::class, 'deactivate']);
    Route::post('/admin/users/{user}/reactivate', [AdminUsersController::class, 'reactivate']);
    Route::post('/admin/users/{user}/reset-password', [AdminUsersController::class, 'resetPassword']);

    // اعتماد الجداول والامتحانات المعلّقة (مجلس ينشئ ← المدير يعتمد ← الجميع يرى)
    Route::get('/admin/approvals', [AdminApprovalsController::class, 'index']);
    Route::post('/admin/approvals/timetables/{timetable}/approve', [AdminApprovalsController::class, 'approveTimetable']);
    Route::post('/admin/approvals/timetables/{timetable}/unapprove', [AdminApprovalsController::class, 'unapproveTimetable']);
    Route::post('/admin/approvals/exams/{exam}/approve', [AdminApprovalsController::class, 'approveExam']);
    Route::post('/admin/approvals/exams/{exam}/unapprove', [AdminApprovalsController::class, 'unapproveExam']);

    // البيانات الرئيسية والخطط الدراسية (المدير: قراءة/إدارة — منشئ الجداول الوحيد الآن مجلس المدرسين)
    Route::get('/admin/master-data', [AdminController::class, 'masterData']);
    Route::get('/admin/plans', [AdminController::class, 'plansIndex']);
    Route::post('/admin/plans/{group}', [AdminController::class, 'plansStore']);

    // إدارة الكليات والأقسام والقاعات والفصول الدراسية (CRUD كامل + audit)
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
    Route::post('/admin/semesters/{semester}/archive', [AdminMasterDataController::class, 'semesterArchive'])->middleware('throttle:12,1,archive');
    Route::post('/admin/semesters/{semester}/unarchive', [AdminMasterDataController::class, 'semesterUnarchive']);
    Route::delete('/admin/semesters/{semester}', [AdminMasterDataController::class, 'semesterDelete']);

    // لوحة الأدمن الرسومية + الأرشفة للقراءة فقط
    Route::get('/admin/dashboard-stats', [AdminDashboardController::class, 'dashboardStats']);
    Route::get('/admin/semesters/{semester}/archived', [AdminDashboardController::class, 'archivedSchedule']);

    // النسخ الاحتياطي (إنشاء/قائمة/تحميل مباشر) — إنشاء: 6/دقيقة؛ القائمة/التنزيل: 30/دقيقة
    Route::post('/admin/backup/create', [AdminBackupController::class, 'create'])->middleware('throttle:6,1,backup');
    Route::get('/admin/backup/list', [AdminBackupController::class, 'list'])->middleware('throttle:30,1,backup-list');
    Route::get('/admin/backup/download/{file}', [AdminBackupController::class, 'download'])->middleware('throttle:30,1,backup-download');
});

// ── syst-engine passthrough (مطوّرين — خلف المصادقة) ──
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/syst-engine/health', [EnginePassthrough::class, 'health']);
    Route::post('/syst-engine/check', [EnginePassthrough::class, 'check']);
});
