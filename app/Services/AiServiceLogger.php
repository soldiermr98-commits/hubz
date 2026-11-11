<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use Throwable;

/**
 * S9 — لاگ تلاش‌های اتصال به سرویس‌های هوش مصنوعی.
 *
 * فقط وضعیت (موفق/ناموفق)، کد HTTP و راهنمای ثابت فارسی ثبت می‌شود؛
 * هیچ کلید، متن درخواست، پاسخ کامل ارائه‌دهنده یا داده محرمانه در لاگ نمی‌رود.
 * شکست ثبت لاگ هرگز نباید درخواست اصلی را مختل کند.
 */
final class AiServiceLogger
{
    public static function log(string $provider, string $model, bool $ok, ?int $statusCode, string $message): void
    {
        try {
            DB::query(
                'INSERT INTO ai_service_logs (provider, model, ok, status_code, message)
                 VALUES (:p, :m, :ok, :sc, :msg)',
                [
                    'p' => mb_substr(trim($provider), 0, 20),
                    'm' => mb_substr(trim($model), 0, 120),
                    'ok' => $ok ? 1 : 0,
                    'sc' => $statusCode,
                    'msg' => mb_substr(trim($message), 0, 500),
                ]
            );
        } catch (Throwable $e) {
            error_log('AI service log failed: ' . get_class($e));
        }
    }

    /** آخرین تلاش‌های یک سرویس برای نمایش در پنل؛ فقط داده غیرمحرمانه. */
    public static function recent(string $provider, int $limit = 15): array
    {
        try {
            $limit = max(1, min(50, $limit));
            return DB::fetchAll(
                "SELECT ok, status_code, message, created_at
                 FROM ai_service_logs
                 WHERE provider = :p
                 ORDER BY id DESC LIMIT {$limit}",
                ['p' => mb_substr(trim($provider), 0, 20)]
            );
        } catch (Throwable) {
            return [];
        }
    }
}
