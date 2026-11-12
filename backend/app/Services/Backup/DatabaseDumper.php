<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * DatabaseDumper — نسخة الإنتاج من BackupGateway.
 * ينفذ mysqldump كاملًا ويُرجع اسم الملف الناتج (aust_graduation_<Y-m-d_H-i-s>.sql)، أو null عند الفشل.
 */
class DatabaseDumper implements BackupGateway
{
    public function dump(): ?string
    {
        try {
            return $this->performDump();
        } catch (\Throwable $e) {
            Log::error('[syst:backup] استثناء غير متوقع أثناء النسخ', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function performDump(): ?string
    {
        $dir = storage_path('backups');
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0700, true);
        }

        $config = config('database.connections.mysql');
        $database = $config['database'];
        $host = $config['host'];
        $port = (string) ($config['port'] ?? 3306);
        $username = $config['username'];
        $password = (string) ($config['password'] ?? '');
        // منع حقن أسطر الأوامر عبر ملف defaults-extra-file — كلمة المرور تُكتب كما هي دون CRLF
        $password = strtr($password, ["\r" => '', "\n" => '']);

        // اسم فريد دائمًا (التاريخ والثانية واللاحقة) — لا سباق على اسم الملف مهما توالى النداء ضمن الثانية
        $filename = 'aust_graduation_'.now()->format('Y-m-d_His').'_'.substr(uniqid(), -4).'.sql';
        $target = $dir.DIRECTORY_SEPARATOR.$filename;

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

        $extra = tempnam(sys_get_temp_dir(), 'syst_my_');
        if ($extra === false) {
            Log::error('[syst:backup] تعذر إنشاء ملف مؤقت لمفتاح الاتصال');

            return null;
        }
        file_put_contents($extra, "[client]\npassword=".$password."\n");
        @chmod($extra, 0600);

        try {
            $args = [
                escapeshellarg($mysqldump),
                '--defaults-extra-file='.escapeshellarg($extra),
                '-h'.escapeshellarg($host),
                '-P'.escapeshellarg($port),
                '-u'.escapeshellarg($username),
                '--single-transaction',
                '--routines',
                '--triggers',
                escapeshellarg($database),
                '--result-file='.escapeshellarg($target),
            ];
            exec(implode(' ', $args).' 2>&1', $out, $code);
        } catch (\Throwable $e) {
            Log::error('[syst:backup] استثناء أثناء mysqldump', ['error' => $e->getMessage()]);
            @unlink($extra);
            @unlink($target);

            return null;
        } finally {
            @unlink($extra);
        }

        if ($code !== 0 || ! file_exists($target) || filesize($target) < 100) {
            Log::error('[syst:backup] فشل mysqldump', ['exit_code' => $code, 'output' => implode("\n", $out)]);
            @unlink($target);

            return null;
        }

        @chmod($target, 0600);

        // الاحتفاظ بآخر 10 نسخ فقط
        $kept = collect(File::files($dir))
            ->filter(fn ($f) => $f->getExtension() === 'sql')
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();
        foreach ($kept->slice(10) as $old) {
            File::delete($old->getPathname());
        }

        return $filename;
    }
}
