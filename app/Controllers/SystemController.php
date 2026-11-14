<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Services\BackupService;
use App\Services\HealthService;
use Throwable;

/**
 * S24 — اندپوینت‌های سلامت سیستم و بکاپ (فقط مدیر ارشد).
 * همهٔ عملیات فقط-خواندنی یا موضعی (ساخت بکاپ روی همین هاست) هستند و
 * هیچ داده‌ای خارج از سرور ارسال نمی‌شود.
 */
final class SystemController
{
    /** GET /api/v1/system/health */
    public static function health(Request $request): void
    {
        Auth::requireRole(['admin']);
        try {
            Response::json(HealthService::run());
        } catch (Throwable $e) {
            Response::json(['ok' => false, 'error' => 'بررسی سلامت سیستم ناموفق بود: ' . $e->getMessage()], 500);
        }
    }

    /** GET /api/v1/system/backups */
    public static function backupsList(Request $request): void
    {
        Auth::requireRole(['admin']);
        try {
            Response::json([
                'ok' => true,
                'backups' => BackupService::recent(30),
                'directory' => BackupService::directory(),
                'retention_days' => BackupService::retentionDays(),
            ]);
        } catch (Throwable $e) {
            Response::json(['ok' => false, 'error' => 'خواندن فهرست بکاپ‌ها ناموفق بود؛ مایگریشن ۰۲۲ را اجرا کنید.'], 500);
        }
    }

    /** POST /api/v1/system/backup */
    public static function backupRun(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin']);
        try {
            $result = BackupService::run('panel');
            if (($result['status'] ?? '') === 'busy') {
                Response::json(['ok' => false, 'error' => $result['message'] ?? 'بکاپ دیگری در حال اجراست.'], 409);
            }
            if (($result['status'] ?? '') === 'failed') {
                Response::json(['ok' => false, 'error' => 'بکاپ ناموفق بود: ' . ($result['error'] ?? 'خطای نامشخص')], 500);
            }
            Response::json([
                'ok' => true,
                'result' => $result,
                'message' => sprintf(
                    'بکاپ «%s» ساخته شد (%s، %d جدول، %d سطر) در %d میلی‌ثانیه.',
                    (string) ($result['filename'] ?? ''),
                    HealthService::formatBytes((int) ($result['size_bytes'] ?? 0)),
                    (int) ($result['tables'] ?? 0),
                    (int) ($result['rows'] ?? 0),
                    (int) ($result['duration_ms'] ?? 0)
                ),
            ]);
        } catch (Throwable $e) {
            Response::json(['ok' => false, 'error' => 'اجرای بکاپ ناموفق بود: ' . $e->getMessage()], 500);
        }
    }
}
