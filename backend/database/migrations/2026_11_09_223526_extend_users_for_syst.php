<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('name');
            $table->string('role', 20)->default('admin')->after('username'); // admin|dept|guard|batch
            $table->foreignId('faculty_id')->nullable()->constrained('faculties')->nullOnDelete()->after('role');
            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete()->after('faculty_id');
            $table->timestamp('last_login_at')->nullable()->after('batch_id');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['faculty_id']);
            $table->dropForeign(['batch_id']);
            $table->dropColumn(['username', 'role', 'faculty_id', 'batch_id', 'last_login_at', 'deleted_at']);
        });
    }
};
