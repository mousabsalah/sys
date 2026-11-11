<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupGateway;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'syst:backup';

    protected $description = 'Dump قاعدة بيانات aust_graduation كاملة إلى ملف .sql داخل storage/backups';

    /** اسم آخر ملف نُشئ فعليًا — تستهلكه المتحكمات عبر app(BackupGateway::class) */
    public static ?string $lastCreatedFile = null;

    public function handle(BackupGateway $dumper): int
    {
        self::$lastCreatedFile = null;

        $filename = $dumper->dump();
        if ($filename === null) {
            $this->error('فشل إنشاء النسخة الاحتياطية — راجع سجل لارافيل.');

            return self::FAILURE;
        }

        self::$lastCreatedFile = $filename;
        $sizeKb = round((int) filesize(storage_path('backups/'.$filename)) / 1024, 1);
        $this->info("تم إنشاء النسخة: {$filename} ({$sizeKb} KB)");

        return self::SUCCESS;
    }
}
