<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BackupDatabase extends Command
{
    protected $signature = 'syst:backup';

    protected $description = 'Dump قاعدة بيانات aust_graduation كاملة إلى ملف .sql داخل storage/backups';

    /** اسم آخر ملف نُشئ فعليًا — تستهلكه المتحكمات بدل البحث حسب mtime */
    public static ?string $lastCreatedFile = null;

    public function handle(): int
    {
        self::$lastCreatedFile = null;

        $dir = storage_path('backups');
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0700, true);
        }

        $config = config('database.connections.mysql');
        $database = $config['database'];
        $host = $config['host'];
        $port = (string) ($config['port'] ?? 3306);
        $username = $config['username'];
        $password = $config['password'] ?? '';

        $filename = 'aust_graduation_'.now()->format('Y-m-d_His').'.sql';
        $target = $dir.DIRECTORY_SEPARATOR.$filename;

        // البحث عن mysqldump في مسار XAMPP القياسي على ويندوز ثم المسار العام
        $candidates = [
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            'mysqldump',
        ];
        $mysqldump = null;
        foreach ($candidates as $candidate) {
            if ($candidate === 'mysqldump' || file_exists($candidate)) {
                $mysqldump = $candidate;
                break;
            }
        }

        // كلمة المرور: تمرر عبر ملف defaults-extra-file مؤقت لتفادي ظهورها في قائمة العمليات
        // ملاحظة: --defaults-extra-file يجب أن يكون أول معامل على سطر الأوامر
        $extra = tempnam(sys_get_temp_dir(), 'syst_my_');
        file_put_contents($extra, "[client]\npassword=".$password."\n");
        chmod($extra, 0600);
        $args = [
            escapeshellarg($mysqldump),
            '--defaults-extra-file='.escapeshellarg($extra),
            '-h'.escapeshellarg($host),
            '-P'.escapeshellarg($port),
            '-u'.escapeshellarg($username),
        ];
        $args[] = '--single-transaction';
        $args[] = '--routines';
        $args[] = '--triggers';
        $args[] = escapeshellarg($database);
        $args[] = '--result-file='.escapeshellarg($target);

        $cmd = implode(' ', $args);
        exec($cmd.' 2>&1', $out, $code);
        @unlink($extra);

        if ($code !== 0 || ! file_exists($target) || filesize($target) < 100) {
            $this->error('فشل إنشاء النسخة الاحتياطية: '.implode("\n", $out));
            @unlink($target);

            return self::FAILURE;
        }

        @chmod($target, 0600);
        self::$lastCreatedFile = $filename;

        // الاحتفاظ بآخر 10 نسخ فقط
        $kept = collect(File::files($dir))
            ->filter(fn ($f) => $f->getExtension() === 'sql')
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();
        foreach ($kept->slice(10) as $old) {
            File::delete($old->getPathname());
        }

        $this->info("تم إنشاء النسخة: {$filename} (".round(filesize($target) / 1024, 1).' KB)');

        return self::SUCCESS;
    }
}
