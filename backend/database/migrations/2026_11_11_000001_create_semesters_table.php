<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->constrained('faculties')->restrictOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->restrictOnDelete();
            $table->string('academic_term', 20);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['department_id', 'batch_id', 'academic_term']);
        });

        Schema::table('timetables', function (Blueprint $table) {
            $table->foreignId('semester_id')->nullable()->after('academic_term')->constrained('semesters')->nullOnDelete();
        });
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->foreignId('semester_id')->nullable()->after('academic_term')->constrained('semesters')->nullOnDelete();
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('semester_id')->nullable()->after('date')->constrained('semesters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('timetables', function (Blueprint $table) { $table->dropForeign(['semester_id']); });
        Schema::table('exam_sessions', function (Blueprint $table) { $table->dropForeign(['semester_id']); });
        Schema::table('bookings', function (Blueprint $table) { $table->dropForeign(['semester_id']); });
        Schema::dropIfExists('semesters');
    }
};
