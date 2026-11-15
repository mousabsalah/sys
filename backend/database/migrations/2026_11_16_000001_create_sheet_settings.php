<?php

use App\Services\OfficialSheet\OfficialSheetConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إعدادات «الورقة الرسمية» — صف JSON واحد يُعدّله مجلس المدرسين.
 * يُقرأ من /api/council/sheet-settings، ويُدمج معه داخل /api/council/timetables-official.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheet_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->longText('value');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        DB::table('sheet_settings')->insert([
            'key' => 'official_sheet',
            'value' => OfficialSheetConfig::defaultJson(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_settings');
    }
};
