<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس ناقصة — الاستعلامات الساخنة (available now / تعارض الامتحانات / نافذة الحجوزات / إذن الفصول المؤرشفة)
 * كانت تعمل كـ full scan في الاتجاه المفقود (whereDate / groupBy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timetables', function (Blueprint $table) {
            $table->index('day_of_week', 'timetables_day_of_week_index');
            $table->index('academic_term', 'timetables_academic_term_index');
        });
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->index('exam_date', 'exam_sessions_exam_date_index');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->index('date', 'bookings_date_index');
        });
        Schema::table('semesters', function (Blueprint $table) {
            $table->index('archived_at', 'semesters_archived_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('timetables', function (Blueprint $table) {
            $table->dropIndex('timetables_day_of_week_index');
            $table->dropIndex('timetables_academic_term_index');
        });
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropIndex('exam_sessions_exam_date_index');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_date_index');
        });
        Schema::table('semesters', function (Blueprint $table) {
            $table->dropIndex('semesters_archived_at_index');
        });
    }
};
