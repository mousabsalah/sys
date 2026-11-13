<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مجلس المدرسين — البنية التمهيدية.
 * 1) timetables/exam_sessions: عمود status (pending غير معتمد | approved معتمد) — الموجود حاليًا: معتمد.
 * 2) faculty (المدرسين): degree — الدرجة العلمية من قائمة ثابتة (معيد · محاضر · أستاذ مساعد · أستاذ مشارك · أستاذ).
 * 3) lecturer_faculties (N:M): ربط المدرس بأكثر من كلية (كليات المدير).
 * 4) faculty.department_id يصير اختياريًا — مجلس المدرسين نطاقه الجامعة كلها.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) pending/approved — الموجود معتمد (السلوك قبل هذا التغيير لم يتغير)
        Schema::table('timetables', function (Blueprint $table) {
            $table->string('status', 10)->default('pending')->after('academic_term');
        });
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->string('status', 10)->default('pending')->after('academic_term');
        });
        DB::table('timetables')->update(['status' => 'approved']);
        DB::table('exam_sessions')->update(['status' => 'approved']);
        Schema::table('timetables', function (Blueprint $table) {
            $table->index('status', 'timetables_status_index');
        });
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->index('status', 'exam_sessions_status_index');
        });

        // 2) الدرجة العلمية للمدرس
        Schema::table('faculty', function (Blueprint $table) {
            $table->string('degree', 30)->nullable()->after('full_name');
        });

        // 3) ربط المدرس بأكثر من كلية (جدول وسيط)
        Schema::create('lecturer_faculties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lecturer_id')->constrained('faculty')->cascadeOnDelete();
            $table->foreignId('faculty_id')->constrained('faculties')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['lecturer_id', 'faculty_id']);
            $table->index('faculty_id');
        });

        // 4) القسم الأُم اختياري — مجلس المدرسين نطاقه الجامعة كلها
        Schema::table('faculty', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecturer_faculties');
        Schema::table('faculty', function (Blueprint $table) {
            $table->dropColumn('degree');
        });
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropIndex('exam_sessions_status_index');
            $table->dropColumn('status');
        });
        Schema::table('timetables', function (Blueprint $table) {
            $table->dropIndex('timetables_status_index');
            $table->dropColumn('status');
        });
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement('ALTER TABLE `faculty` MODIFY `department_id` BIGINT UNSIGNED NOT NULL');
        }
    }
};
