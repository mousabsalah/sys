<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->foreignId('faculty_id')->constrained('faculty')->restrictOnDelete();
            $table->foreignId('group_id')->constrained('groups')->restrictOnDelete();
            $table->foreignId('hall_id')->constrained('halls')->restrictOnDelete();
            $table->string('day_of_week', 10);
            $table->time('start_time');
            $table->time('end_time');
            $table->string('academic_term', 20); // 2026/الفصل الأول
            $table->timestamps();
            $table->index(['hall_id', 'day_of_week']);
            $table->index(['faculty_id', 'day_of_week']);
            $table->index(['group_id', 'day_of_week']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hall_id')->constrained('halls')->restrictOnDelete();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('activity_name', 100)->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['confirmed', 'cancelled'])->default('confirmed');
            $table->timestamps();
            $table->index(['hall_id', 'date']);
        });

        Schema::create('guard_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hall_id')->constrained('halls')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('action', ['lock', 'unlock']);
            $table->dateTime('action_at')->useCurrent();
            $table->string('notes', 255)->nullable();
            $table->index(['hall_id', 'action_at']);
        });

        Schema::create('timetable_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->restrictOnDelete();
            $table->foreignId('timetable_id')->nullable()->constrained('timetables')->nullOnDelete();
            $table->enum('update_type', ['add', 'edit', 'cancel']);
            $table->string('description', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['group_id', 'created_at']);
        });

        Schema::create('exam_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->foreignId('group_id')->constrained('groups')->restrictOnDelete();
            $table->foreignId('hall_id')->constrained('halls')->restrictOnDelete();
            $table->date('exam_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('exam_type', ['اختبار قصير', 'ميدترم', 'فاينل']);
            $table->string('academic_term', 20);
            $table->timestamps();
            $table->index(['hall_id', 'exam_date']);
            $table->index(['group_id', 'exam_date']);
        });

        Schema::create('exam_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->restrictOnDelete();
            $table->foreignId('exam_session_id')->nullable()->constrained('exam_sessions')->nullOnDelete();
            $table->enum('update_type', ['add', 'edit', 'cancel']);
            $table->string('description', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['group_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_updates');
        Schema::dropIfExists('exam_sessions');
        Schema::dropIfExists('timetable_updates');
        Schema::dropIfExists('guard_locks');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('timetables');
    }
};
