<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdminMailService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AdminMailController
{
    private static function authorize(Request $request, bool $change): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        Auth::requireRole(['admin']);
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        $secure = $https === 'on' || $https === '1';
        $trusted = Config::get('security.trusted_proxy_ips', []);
        if (!$secure && is_array($trusted) && in_array($request->ip(), $trusted, true)) {
            $secure = strtolower((string)$request->header('X-Forwarded-Proto', '')) === 'https';
        }
        if (!$secure && PHP_SAPI === 'cli-server' && getenv('HHX_ALLOW_TEST_FIXTURES') === '1'
            && in_array($request->ip(), ['127.0.0.1','::1'], true)
            && preg_match('/^hhx_test_[a-z0-9_]+$/', (string)Config::get('db.database', ''))) $secure = true;
        if (!$secure) Response::json(['ok'=>false,'error'=>'تنظیمات ایمیل فقط از طریق HTTPS در دسترس است.'], 400);
        if ($change) Csrf::verifyOrFail($request);
    }

    public static function index(Request $request): void
    {
        self::authorize($request, false);
        try { Response::json(['ok'=>true,'settings'=>AdminMailService::status()]); }
        catch (Throwable $e) { Response::json(['ok'=>false,'error'=>'تنظیمات ایمیل خوانده نشد.'], 500); }
    }

    public static function save(Request $request): void
    {
        self::authorize($request, true);
        try {
            AdminMailService::save($request->json());
            Response::json(['ok'=>true,'message'=>'تنظیمات ذخیره شد. برای بررسی ارسال، ایمیل آزمایشی بفرستید.']);
        } catch (InvalidArgumentException $e) {
            Response::json(['ok'=>false,'error'=>$e->getMessage()], 422);
        } catch (RuntimeException $e) {
            if ($e->getCode() === 409) Response::json(['ok'=>false,'error'=>'تنظیمات هم‌زمان تغییر کرده‌اند؛ صفحه را تازه کنید.'], 409);
            Response::json(['ok'=>false,'error'=>'ذخیره انجام نشد؛ تنظیمات خصوصی هاست را بررسی کنید.'], 500);
        } catch (Throwable $e) {
            Response::json(['ok'=>false,'error'=>'ذخیره انجام نشد؛ تنظیمات هاست را بررسی کنید.'], 500);
        }
    }

    public static function test(Request $request): void
    {
        self::authorize($request, true);
        try {
            if (!AdminMailService::send('آزمایش اعلان حامی‌هاب', 'این پیام آزمایشی از تنظیمات ایمیل حامی‌هاب است.', true)) throw new RuntimeException();
            Response::json(['ok'=>true,'message'=>'پیام به سرویس ارسال تحویل شد؛ دریافت در صندوق و پوشه هرزنامه را بررسی کنید.']);
        } catch (Throwable $e) {
            Response::json(['ok'=>false,'error'=>'ارسال تأیید نشد. آدرس‌ها، اتصال و تنظیمات سرویس ایمیل هاست را بررسی کنید.'], 502);
        }
    }
}
