<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تصحيح: التهجير السابق أضاف timetables_status_index2 على نفس العمود status — مكرر بالكامل مع timetables_status_index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timetables', function (Blueprint $table) {
            $table->dropIndex('timetables_status_index2');
        });
    }

    public function down(): void
    {
        Schema::table('timetables', function (Blueprint $table) {
            $table->index('status', 'timetables_status_index2');
        });
    }
};
