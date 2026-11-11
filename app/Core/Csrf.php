<?php
declare(strict_types=1);

namespace App\Core;

/**
 * مدیریت توکن‌های ضد جعل درخواست (CSRF) با بررسی هدر X-CSRF-Token
 */
final class Csrf
{
    private const SESSION_KEY = 'hhx_csrf_token';

    /**
     * دریافت یا تولید توکن CSRF برای نشست فعلی
     */
    public static function token(): string
    {
        Auth::startSessionIfNeeded();

        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * بررسی صحت توکن CSRF برای درخواست‌های غیر GET
     * استثناها: /api/v1/track و مسیرهای collector/ai که با Bearer token کار می‌کنند
     */
    public static function verifyOrFail(Request $request): void
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $path = $request->path();

        // استثنای مسیر ردیابی کلیک و مسیرهای ارتباط با collector-remote
        if (
            in_array($path, ['/api/v1/events/track', '/api/v1/collector/ingest', '/api/v1/collector/photo', '/api/v1/ai/tasks/complete'], true)
        ) {
            return;
        }

        $headerName = (string) Config::get('security.csrf_header', 'X-CSRF-Token');
        $provided = $request->header($headerName) ?? (string) $request->input('_csrf', '');
        $expected = self::token();

        if ($provided === '' || !hash_equals($expected, $provided)) {
            Response::json([
                'ok' => false,
                'error' => 'توکن امنیتی صفحه (CSRF) منقضی یا نامعتبر است. لطفاً صفحه را رفرش کرده و دوباره تلاش کنید.',
                'code' => 'csrf_invalid',
            ], 403);
        }
    }
}
