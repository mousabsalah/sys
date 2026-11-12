<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CHECK constraints — تمنع بيانات منطقية خاطئة على مستوى قاعدة البيانات نفسها.
 * حراسة: تُطبق على MySQL/MariaDB فقط — مجموعة الاختبارات تعمل على sqlite :memory: وتُتخطاها.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE halls ADD CONSTRAINT halls_capacity_positive CHECK (capacity > 0)');
        DB::statement('ALTER TABLE timetables ADD CONSTRAINT timetables_time_order CHECK (start_time < end_time)');
        DB::statement('ALTER TABLE exam_sessions ADD CONSTRAINT exam_sessions_time_order CHECK (start_time < end_time)');
        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_time_order CHECK (start_time < end_time)');
        DB::statement('ALTER TABLE batches ADD CONSTRAINT batches_students_non_negative CHECK (student_count >= 0)');
        DB::statement('ALTER TABLE groups ADD CONSTRAINT groups_students_non_negative CHECK (student_count >= 0)');
        DB::statement('ALTER TABLE semesters ADD CONSTRAINT semesters_dates_order CHECK (start_date IS NULL OR end_date IS NULL OR start_date <= end_date)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE halls DROP CHECK halls_capacity_positive');
        DB::statement('ALTER TABLE timetables DROP CHECK timetables_time_order');
        DB::statement('ALTER TABLE exam_sessions DROP CHECK exam_sessions_time_order');
        DB::statement('ALTER TABLE bookings DROP CHECK bookings_time_order');
        DB::statement('ALTER TABLE batches DROP CHECK batches_students_non_negative');
        DB::statement('ALTER TABLE groups DROP CHECK groups_students_non_negative');
        DB::statement('ALTER TABLE semesters DROP CHECK semesters_dates_order');
    }
};
