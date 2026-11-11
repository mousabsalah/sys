<?php

declare(strict_types=1);

namespace App\Services\Backup;

/**
 * BackupGateway — حاوية التجريد للنسخ الاحتياطية.
 * الإنتاج: mysqldump عبر DatabaseDumper. الاختبارات: Fake يفحص المسارات دون أي عملية خارجية.
 */
interface BackupGateway
{
    /** ينفذ النسخ ويُرجع اسم الملف الناتج، أو null عند الفشل — لا يرمي أبدًا. */
    public function dump(): ?string;
}
