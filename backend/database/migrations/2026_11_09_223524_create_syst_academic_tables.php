<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();      // صباحي / مسائي / إلخ
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('day_of_week', 10);          // السبت .. الخميس
            $table->time('start_time');
            $table->time('end_time');
            $table->string('label', 30);                // "الفترة الأولى" ...
            $table->smallInteger('sort_order')->default(1);
            $table->timestamps();
            $table->index(['program_id', 'day_of_week', 'sort_order']);
        });

        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->unsignedInteger('student_count')->default(0);
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('program_id')->constrained('programs')->restrictOnDelete();
            $table->softDeletes();
            $table->timestamps();
            $table->index('department_id');
            $table->index('program_id');
        });

        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->enum('track', ['دبلوم', 'بكلاريوس']);
            $table->unsignedInteger('student_count');
            $table->timestamps();
            $table->unique(['batch_id', 'track']);
        });

        Schema::create('faculty', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 100);
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->string('email', 100)->unique();
            $table->string('phone', 20)->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('batches');
        Schema::dropIfExists('periods');
        Schema::dropIfExists('programs');
    }
};
