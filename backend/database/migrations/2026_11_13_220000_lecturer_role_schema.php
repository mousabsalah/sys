<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دور «مدرس» — حساب كل مدرس + طلبات التبديل بين مدرسين مباشرةً.
 * 1) users.lecturer_id → faculty(id) nullable unique — ربط 1:1 بين حساب المدرس وسجله.
 * 2) substitute_requests — طلب "مدرس آخر يغطي المحاضرة": المدرس الطالب يطلب ← المستهدف يقبل/يرفض.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('lecturer_id')->nullable()->unique()->after('batch_id')
                ->constrained('faculty')->nullOnDelete();
        });

        Schema::create('substitute_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timetable_id')->constrained('timetables')->cascadeOnDelete();
            $table->foreignId('requested_by_lecturer_id')->constrained('faculty')->cascadeOnDelete();
            $table->foreignId('substitute_lecturer_id')->constrained('faculty')->restrictOnDelete();
            $table->string('status', 10)->default('pending'); // pending|accepted|rejected|cancelled
            $table->timestamps();
            $table->index(['substitute_lecturer_id', 'status'], 'substitute_requests_target_index');
            $table->index(['requested_by_lecturer_id', 'status'], 'substitute_requests_requester_index');
            $table->index('status');
        });

        // timetable status: pending|approved|cancelled — صف «ملغية» يُستبعد من فحص التعارض ويُبقي السجل للتدقيق
        Schema::table('timetables', function (Blueprint $table) {
            $table->index('status', 'timetables_status_index2');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('substitute_requests');
        Schema::table('timetables', function (Blueprint $table) {
            $table->dropIndex('timetables_status_index2');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lecturer_id');
        });
    }
};
