<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Services\ManualImportService;
use App\Services\ApifyImportService;
use InvalidArgumentException;
use Throwable;

final class ManualImportController
{
    private static function input(Request $request, int $maxBytes = 5 * 1024 * 1024): array
    {
        Auth::requireRole(['admin']);
        Csrf::verifyOrFail($request);
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes || strlen($request->rawBody()) > $maxBytes) {
            Response::json(['ok'=>false,'error'=>'حجم درخواست از سقف مجاز بیشتر است؛ فایل را به بخش‌های کوچک‌تر تقسیم کنید.'], 413);
        }
        return $request->json();
    }

    public static function prepare(Request $request): void
    {
        $data = self::input($request);
        try {
            if (!is_array($data['items'] ?? null)) throw new InvalidArgumentException('فایل باید آرایه JSON باشد.');
            $startedAt = $data['run_started_at'] ?? '';
            if (!is_string($startedAt) || strlen($startedAt) > 40) throw new InvalidArgumentException('زمان اجرای منبع معتبر نیست.');
            Response::json(['ok'=>true] + ApifyImportService::prepare($data['items'], $startedAt));
        } catch (InvalidArgumentException $e) {
            Response::json(['ok'=>false,'error'=>$e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('JSON preparation failed: ' . $e->getMessage());
            Response::json(['ok'=>false,'error'=>'تبدیل فایل ناموفق بود.'], 500);
        }
    }

    public static function preview(Request $request): void
    {
        // The prepared request carries original raw fields and mapped reviews.
        $data = self::input($request, 10 * 1024 * 1024);
        try {
            $options = ManualImportService::options(is_array($data['options'] ?? null) ? $data['options'] : []);
            if (!is_array($data['items'] ?? null)) throw new InvalidArgumentException('فایل باید آرایه JSON باشد.');
            Response::json(['ok'=>true,'rows'=>ManualImportService::preview($data['items'], $options)]);
        } catch (InvalidArgumentException $e) {
            Response::json(['ok'=>false,'error'=>$e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('Manual import preview failed: ' . $e->getMessage());
            Response::json(['ok'=>false,'error'=>'پیش‌نمایش ناموفق بود؛ مایگریشن‌ها و گزارش سرور را بررسی کنید.'], 500);
        }
    }

    public static function apply(Request $request): void
    {
        $data = self::input($request, 10 * 1024 * 1024);
        try {
            $options = ManualImportService::options(is_array($data['options'] ?? null) ? $data['options'] : []);
            if (!is_array($data['entries'] ?? null)) throw new InvalidArgumentException('رکوردهای انتخاب‌شده معتبر نیستند.');
            Response::json(['ok'=>true,'results'=>ManualImportService::apply($data['entries'], $options)]);
        } catch (InvalidArgumentException $e) {
            Response::json(['ok'=>false,'error'=>$e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('Manual import batch failed: ' . $e->getMessage());
            Response::json(['ok'=>false,'error'=>'ثبت این نوبت ناموفق بود؛ گزارش سرور را بررسی کنید.'], 500);
        }
    }
}
