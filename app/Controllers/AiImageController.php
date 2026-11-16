<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Services\AiImageService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * S30 — کنترلر سرویس تولید تصویر مقاله (ورکر مستقل Cloudflare / flux-1-schnell)
 *
 * کانفیگ و تولید فقط از نشست ادمین/ویراستار روی HTTPS؛ سکرت هرگز به مرورگر برنمی‌گردد
 * و خطاهای داخلی بدون جزئیات فنی پاس داده می‌شوند (همان انضباط AiArticleController).
 */
final class AiImageController
{
    private static function secure(Request $request): void
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $isSecure = $https === 'on' || $https === '1';
        $trusted = Config::get('security.trusted_proxy_ips', []);
        if (!$isSecure && is_array($trusted) && in_array($request->ip(), $trusted, true)) {
            $isSecure = strtolower((string) $request->header('X-Forwarded-Proto', '')) === 'https';
        }
        // Only the isolated test router, loopback client, and explicitly named fixture DB may use HTTP.
        if (!$isSecure && PHP_SAPI === 'cli-server' && getenv('HHX_ALLOW_TEST_FIXTURES') === '1'
            && in_array($request->ip(), ['127.0.0.1', '::1'], true)
            && preg_match('/^hhx_test_[a-z0-9_]+$/', (string) Config::get('db.database', ''))) {
            $isSecure = true;
        }
        if (!$isSecure) {
            Response::json(['ok' => false, 'error' => 'مدیریت سرویس تصویر فقط با اتصال امن HTTPS امکان‌پذیر است.'], 400);
        }
    }

    private static function authorize(Request $request, bool $mutating, bool $adminOnly = false): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        Auth::requireRole($adminOnly ? ['admin'] : ['admin', 'editor']);
        self::secure($request);
        if ($mutating) Csrf::verifyOrFail($request);
    }

    private static function service(): AiImageService
    {
        return new AiImageService(BASE_PATH);
    }

    /** GET /api/v1/ai/image/status — وضعیت کانفیگ برای پنل */
    public static function status(Request $request): void
    {
        self::authorize($request, false);
        try {
            Response::json(['ok' => true, 'image_service' => self::service()->status()]);
        } catch (Throwable $e) {
            Response::json(['ok' => false, 'error' => 'وضعیت سرویس تصویر خوانده نشد؛ اتصال دیتابیس را بررسی کنید.'], 500);
        }
    }

    /** POST /api/v1/ai/image/config — ذخیره/حذف کانفیگ (فقط ادمین + CSRF) */
    public static function save(Request $request): void
    {
        self::authorize($request, true, true);
        try {
            self::service()->save(
                (string) $request->input('endpoint', ''),
                (string) $request->input('secret', ''),
                (bool) $request->input('enabled', false),
                (bool) $request->input('free_tier_confirmed', false),
                (int) $request->input('version', 0),
                (bool) $request->input('remove', false)
            );
            Response::json([
                'ok'            => true,
                'image_service' => self::service()->status(),
                'message'       => 'تنظیمات سرویس تصویر ذخیره شد.',
            ]);
        } catch (InvalidArgumentException | RuntimeException $e) {
            // پیام‌های authored سرویس امن‌اند؛ جزئیات فنی فیلتر می‌شوند.
            Response::json(['ok' => false, 'error' => $e->getMessage()], $e->getCode() === 409 ? 409 : 422);
        } catch (Throwable $e) {
            Response::json(['ok' => false, 'error' => 'ذخیره تنظیمات سرویس تصویر انجام نشد؛ وضعیت دیتابیس را بررسی کنید.'], 500);
        }
    }

    /**
     * POST /api/v1/ai/image/generate — تولید تصویر + ثبت در کتابخانهٔ رسانه
     * ورودی: prompt (الزامی)، alt_text، width/height اختیاری، confirm_cost (الزامی مثل S29)
     * خروجی: media استاندارد برای ست‌کردن cover_media_id مقاله
     */
    public static function generate(Request $request): void
    {
        self::authorize($request, true);
        if (!(bool) $request->input('confirm_cost', false)) {
            Response::json(['ok' => false, 'error' => 'برای تولید تصویر، مصرف neuron باید در پنل تأیید شود.'], 422);
        }
        try {
            $media = self::service()->generate(
                (string) $request->input('prompt', ''),
                (int) $request->input('width', 1024),
                (int) $request->input('height', 576),
                trim((string) $request->input('alt_text', ''))
            );
            Response::json([
                'ok'     => true,
                'media'  => $media,
                'message' => 'تصویر تولید و در کتابخانهٔ رسانه ذخیره شد.',
            ]);
        } catch (InvalidArgumentException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            // پیام سرویس قبلاً بدون جزئیات فنی/سکرت نوشته شده است.
            Response::json(['ok' => false, 'error' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            Response::json(['ok' => false, 'error' => 'تولید تصویر انجام نشد؛ سرویس را در پنل بررسی کنید.'], 500);
        }
    }
}
