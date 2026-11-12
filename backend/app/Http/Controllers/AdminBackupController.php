<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogger;
use App\Services\Backup\BackupGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class AdminBackupController extends Controller
{
    public function __construct(private AuditLogger $audit, private BackupGateway $dumper) {}

    /**
     * إنشاء نسخة احتياطية فورية (mysqldump كامل للقاعدة).
     */
    public function create(Request $request)
    {
        $file = $this->dumper->dump();

        if (! $file) {
            Log::error('[syst:backup] فشل إنشاء نسخة احتياطية عبر البوابة');

            return response()->json([
                'ok' => false,
                'message_ar' => 'تعذر إنشاء النسخة الاحتياطية الآن — راجع سجل النظام أو حاول لاحقًا.',
            ], 500);
        }

        $sizeKb = round((int) @filesize(storage_path('backups/'.$file)) / 1024, 1);
        $this->audit->log($request, 'backup.create', 'backup', null, ['file' => $file]);

        return response()->json([
            'ok' => true,
            'file' => $file,
            'size_kb' => $sizeKb,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * قائمة النسخ الموجودة (الأحدث أولًا).
     */
    public function list()
    {
        $dir = storage_path('backups');
        if (! File::isDirectory($dir)) {
            return response()->json(['backups' => [], 'total' => 0]);
        }

        $backups = collect(File::files($dir))
            ->filter(fn ($f) => $f->getExtension() === 'sql')
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values()
            ->map(fn ($f) => [
                'file' => $f->getFilename(),
                'size_kb' => round($f->getSize() / 1024, 1),
                'created_at' => date('c', $f->getMTime()),
            ]);

        return response()->json(['backups' => $backups, 'total' => $backups->count()]);
    }

    /**
     * تحميل نسخة .sql مباشرةً. اسم الملف محصور على النمط الرسمي لمنع path traversal.
     */
    public function download(Request $request, string $file)
    {
        if (! preg_match('/^aust_graduation_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}(_[0-9a-f]{4,16})?\.sql$/i', $file)) {
            return response()->json(['ok' => false, 'message_ar' => 'اسم ملف غير صالح.'], 422);
        }
        $path = storage_path('backups'.DIRECTORY_SEPARATOR.$file);
        if (! File::exists($path)) {
            return response()->json(['ok' => false, 'message_ar' => 'الملف غير موجود.'], 404);
        }

        // تنزيل نسخة كاملة = أدق مسار تسريب — يجب أن يترك أثرًا في التدقيق أولًا
        $this->audit->log($request, 'backup.download', 'backup', null, ['file' => $file]);

        return response()->download($path, $file, [
            'Content-Type' => 'application/sql',
        ]);
    }
}
