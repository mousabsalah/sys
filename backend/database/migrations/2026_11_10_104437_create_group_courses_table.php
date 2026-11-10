<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * الخطة الدراسية للشعبة: المقررات المقررة + عدد حصص الأسبوع لكل مقرر.
     * هي المصدر الرسمي لاقتراح CP-SAT للجدول.
     */
    public function up(): void
    {
        Schema::create('group_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->unsignedTinyInteger('sessions_per_week')->default(3);
            $table->timestamps();
            $table->unique(['group_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_courses');
    }
};
