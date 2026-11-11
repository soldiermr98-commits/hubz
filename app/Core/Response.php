<?php
declare(strict_types=1);

namespace App\Core;

/**
 * مدیریت خروجی HTTP، هدرهای امنیتی استاندارد، پاسخ‌های JSON و HTML و ایمن‌سازی خروجی (XSS Escape)
 */
final class Response
{
    /**
     * ارسال هدرهای امنیتی پایه طبق بخش ۶ مشخصات پروژه
     */
    public static function sendSecurityHeaders(bool $isHtml = true): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');

        if ($isHtml) {
            header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://*.tile.openstreetmap.org; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self' data:; connect-src 'self';");
        }
    }

    /**
     * ارسال پاسخ JSON استاندارد با کد وضعیت HTTP
     */
    public static function json(array $data, int $status = 200): never
    {
        self::sendSecurityHeaders(false);
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }

        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        echo is_string($encoded) ? $encoded : '{"ok":false,"error":"خطا در رمزنگاری پاسخ JSON."}';
        exit;
    }

    /**
     * ارسال پاسخ بدون محتوا (مثلاً 204 برای /api/v1/track)
     */
    public static function noContent(int $status = 204): never
    {
        self::sendSecurityHeaders(false);
        if (!headers_sent()) {
            http_response_code($status);
        }
        exit;
    }

    /**
     * ارسال پاسخ HTML با کد وضعیت
     */
    public static function html(string $content, int $status = 200): never
    {
        self::sendSecurityHeaders(true);
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $content;
        exit;
    }

    /** Redirect only to an application-relative path, never to an arbitrary host. */
    public static function redirect(string $path, int $status = 301): never
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, "\r") || str_contains($path, "\n")) {
            throw new \InvalidArgumentException('Invalid redirect path');
        }
        self::sendSecurityHeaders(false);
        header('Cache-Control: public, max-age=3600');
        header('Location: ' . $path, true, $status);
        exit;
    }

    /**
     * ایمن‌سازی رشته برای جلوگیری از XSS در قالب‌های HTML
     */
    public static function e(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
