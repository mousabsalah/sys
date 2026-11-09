<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('halls', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->unsignedInteger('capacity');
            $table->enum('status', ['متاحة', 'صيانة', 'مغلقة'])->default('متاحة');
            $table->enum('hall_type', ['قاعة', 'معمل'])->default('قاعة');
            $table->string('equipment', 255)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->unsignedTinyInteger('credit_hours')->default(3);
            $table->string('required_equipment', 255)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('course_faculty', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->foreignId('faculty_id')->constrained('faculty')->restrictOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->unique(['course_id', 'faculty_id']);
            $table->index('faculty_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_faculty');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('halls');
    }
};
