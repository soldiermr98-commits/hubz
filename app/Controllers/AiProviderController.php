<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Services\AiArticleService;
use App\Services\AiProviderSettings;
use App\Services\AiProviderVaultException;
use App\Services\AiQuotaService;
use App\Services\AiServiceLogger;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Credentials are accepted only from an authenticated administrator over HTTPS. */
final class AiProviderController
{
    private static function secure(Request $request): void
    {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        $isSecure = $https === 'on' || $https === '1';
        $trusted = Config::get('security.trusted_proxy_ips', []);
        if (!$isSecure && is_array($trusted) && in_array($request->ip(), $trusted, true)) {
            $isSecure = strtolower((string)$request->header('X-Forwarded-Proto', '')) === 'https';
        }
        // Only the isolated test router, loopback client, and explicitly named fixture DB may use HTTP.
        if (!$isSecure && PHP_SAPI === 'cli-server' && getenv('HHX_ALLOW_TEST_FIXTURES') === '1'
            && in_array($request->ip(), ['127.0.0.1', '::1'], true)
            && preg_match('/^hhx_test_[a-z0-9_]+$/', (string)Config::get('db.database', ''))) $isSecure = true;
        if (!$isSecure) Response::json(['ok'=>false, 'error'=>'مدیریت کلیدها فقط با اتصال امن HTTPS امکان‌پذیر است.'], 400);
    }

    private static function settings(): AiProviderSettings
    {
        return new AiProviderSettings((array)Config::get('ai', []), BASE_PATH);
    }

    private static function authorize(Request $request, bool $mutating): array
    {
        header('Cache-Control: private, no-store, max-age=0');
        $user = Auth::requireRole(['admin']);
        self::secure($request);
        if ($mutating) Csrf::verifyOrFail($request);
        return $user;
    }

    public static function index(Request $request): void
    {
        self::authorize($request, false);
        try {
            Response::json(['ok'=>true, 'schema_ready'=>AiQuotaService::schemaReady(), 'providers'=>self::settings()->managementStatus()]);
        } catch (Throwable $e) {
            Response::json(['ok'=>false, 'error'=>'وضعیت سرویس‌ها خوانده نشد؛ اتصال دیتابیس و تنظیمات را بررسی کنید.'], 500);
        }
    }

    public static function save(Request $request): void
    {
        self::authorize($request, true);
        $id = $request->input('provider', '');
        $model = $request->input('model', '');
        $key = $request->input('api_key', '');
        $enabled = $request->input('enabled', false);
        $confirmed = $request->input('free_tier_confirmed', false);
        $version = $request->input('version', null);
        $baseUrl = $request->input('base_url', '');
        if (!is_string($id) || !is_string($model) || !is_string($key) || !is_bool($enabled) || !is_bool($confirmed) || !is_int($version) || !is_string($baseUrl)) {
            Response::json(['ok'=>false, 'error'=>'اطلاعات تنظیمات معتبر نیست.'], 422);
        }
        try {
            self::settings()->save($id, $model, $key, $enabled, $confirmed, $version, false, $baseUrl);
            Response::json(['ok'=>true, 'message'=>'تنظیمات ذخیره شد. برای بررسی مدل و اتصال، دکمه تست را بزنید.']);
        } catch (InvalidArgumentException $e) {
            Response::json(['ok'=>false, 'error'=>$e->getMessage()], 422);
        } catch (AiProviderVaultException $e) {
            Response::json(['ok'=>false, 'error'=>$e->getMessage()], 409);
        } catch (RuntimeException $e) {
            if ($e->getCode() === 409) Response::json(['ok'=>false, 'error'=>'تنظیمات هم‌زمان تغییر کرده‌اند؛ صفحه را تازه کنید.'], 409);
            Response::json(['ok'=>false, 'error'=>'ذخیره انجام نشد؛ وضعیت تنظیمات و پشتیبان را بررسی کنید.'], 500);
        } catch (Throwable $e) {
            // Never log a request body, credential, or database exception here.
            Response::json(['ok'=>false, 'error'=>'ذخیره انجام نشد؛ وضعیت هاست را بررسی کنید.'], 500);
        }
    }

    public static function remove(Request $request): void
    {
        self::authorize($request, true);
        $id = $request->input('provider', '');
        $version = $request->input('version', null);
        $catalog = AiArticleService::catalog();
        if (!is_string($id) || !isset($catalog[$id]) || !is_int($version) || $request->input('confirm') !== true) {
            Response::json(['ok'=>false, 'error'=>'حذف کلید را تأیید و سرویس معتبر انتخاب کنید.'], 422);
        }
        try {
            self::settings()->save($id, $catalog[$id]['default_model'], '', false, false, $version, true);
            Response::json(['ok'=>true, 'message'=>'کلید پنل حذف و سرویس غیرفعال شد. اگر کلید قدیمی در فایل خصوصی یا بکاپ بوده، آن نسخه جداگانه باقی می‌ماند.']);
        } catch (RuntimeException $e) {
            if ($e->getCode() === 409) Response::json(['ok'=>false, 'error'=>'تنظیمات هم‌زمان تغییر کرده‌اند؛ صفحه را تازه کنید.'], 409);
            Response::json(['ok'=>false, 'error'=>'حذف انجام نشد؛ وضعیت تنظیمات را بررسی کنید.'], 500);
        } catch (Throwable $e) {
            Response::json(['ok'=>false, 'error'=>'حذف انجام نشد؛ وضعیت هاست را بررسی کنید.'], 500);
        }
    }

    /** S9: آخرین تلاش‌های اتصال سرویس با کد وضعیت دقیق — برای عیب‌یابی ۴۰۳ و پراکسی. */
    public static function logs(Request $request): void
    {
        self::authorize($request, false);
        $id = $request->input('provider', '');
        $limit = (int) $request->input('limit', 15);
        if (!is_string($id) || !isset(AiArticleService::catalog()[$id])) {
            Response::json(['ok'=>false, 'error'=>'سرویس معتبر انتخاب کنید.'], 422);
        }
        try {
            Response::json(['ok'=>true, 'provider'=>$id, 'logs'=>AiServiceLogger::recent($id, $limit)]);
        } catch (Throwable $e) {
            Response::json(['ok'=>false, 'error'=>'خواندن لاگ سرویس ناموفق بود؛ وضعیت دیتابیس را بررسی کنید.'], 500);
        }
    }

    public static function test(Request $request): void
    {
        $user = self::authorize($request, true);
        $id = $request->input('provider', '');
        if (!is_string($id) || !isset(AiArticleService::catalog()[$id]) || $request->input('confirm_cost') !== true) {
            Response::json(['ok'=>false, 'error'=>'سرویس و تأیید هزینه احتمالی تست لازم است.'], 422);
        }
        try {
            if (!AiQuotaService::schemaReady()) Response::json(['ok'=>false, 'error'=>'ابتدا بخش هوش مصنوعی را در پنل آماده کنید.'], 409);
            $service = new AiArticleService(self::settings()->effectiveConfig());
            $prepared = $service->prepareConnectionTest($id);
        } catch (RuntimeException $e) {
            Response::json(['ok'=>false, 'error'=>'سرویس آماده نیست؛ فعال‌بودن، کلید، مدل و تأیید سهمیه را بررسی کنید.'], 422);
        } catch (Throwable $e) {
            Response::json(['ok'=>false, 'error'=>'آماده‌سازی تست انجام نشد.'], 500);
        }
        try {
            if (!AiQuotaService::reserve((int)$user['id'], $id)) Response::json(['ok'=>false, 'error'=>'محدودیت درخواست پنل رسیده است؛ بعداً دوباره تلاش کنید.'], 429);
        } catch (Throwable $e) {
            Response::json(['ok'=>false, 'error'=>'محدودیت درخواست آماده نیست؛ وضعیت دیتابیس را بررسی کنید.'], 500);
        }
        try {
            // One explicit small request; no private content, retries, or automatic fallback.
            $service->send($prepared);
            Response::json(['ok'=>true, 'message'=>'اتصال و پاسخ مدل موفق بود. رایگان‌بودن همچنان به طرح و سهمیه حساب شما بستگی دارد.']);
        } catch (RuntimeException $e) {
            Response::json(['ok'=>false, 'error'=>$e->getMessage()], 502);
        } catch (Throwable $e) {
            Response::json(['ok'=>false, 'error'=>'تست ارتباط انجام نشد؛ شبکه و تنظیمات سرویس را بررسی کنید.'], 500);
        }
    }
}
