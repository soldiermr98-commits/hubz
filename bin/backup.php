<?php
declare(strict_types=1);

// اجرای دستی بکاپ دیتابیس از خط فرمان هاست (بدون نیاز به پنل):
//   php bin/backup.php
// بکاپ روزانهٔ خودکار در cron/worker.php انجام می‌شود؛ این فایل برای
// پشتیبان‌گیری پیش از نصب بسته‌های جدید یا دیباگ است.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$basePath = dirname(__DIR__);
require_once $basePath . '/app/Core/Bootstrap.php';
\App\Core\Bootstrap::init($basePath);

$result = \App\Services\BackupService::run('cli');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit(($result['status'] ?? '') === 'ok' ? 0 : 1);
