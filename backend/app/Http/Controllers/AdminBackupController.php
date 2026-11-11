<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class AdminBackupController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * إنشاء نسخة احتياطية فورية (mysqldump كامل للقاعدة).
     */
    public function create(Request $request)
    {
        Artisan::call('syst:backup');
        $output = Artisan::output();
        $created = collect(File::files(storage_path('backups')))
            ->filter(fn ($f) => $f->getExtension() === 'sql')
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->first();

        $this->audit->log($request, 'backup.create', 'backup', null, [
            'file' => $created?->getFilename(),
        ]);

        if (! $created) {
            return response()->json(['ok' => false, 'message_ar' => 'تعذر إنشاء النسخة الاحتياطية.', 'output' => trim($output)], 500);
        }

        return response()->json([
            'ok' => true,
            'file' => $created->getFilename(),
            'size_kb' => round($created->getSize() / 1024, 1),
            'created_at' => date('c', $created->getMTime()),
        ]);
    }

    /**
     * قائمة النسخ الموجودة (الأحدث أولًا).
     */
    public function list()
    {
        $backups = collect(File::files(storage_path('backups')))
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
     * تحميل نسخة .sql مباشرةً. اسم الملف محصور على نمط aust_graduation_*.sql لمنع path traversal.
     */
    public function download(string $file)
    {
        if (! preg_match('/^aust_graduation_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.sql$/', $file)) {
            return response()->json(['ok' => false, 'message_ar' => 'اسم ملف غير صالح.'], 422);
        }
        $path = storage_path('backups'.DIRECTORY_SEPARATOR.$file);
        if (! File::exists($path)) {
            return response()->json(['ok' => false, 'message_ar' => 'الملف غير موجود.'], 404);
        }

        return response()->download($path, $file, [
            'Content-Type' => 'application/sql',
        ]);
    }
}
