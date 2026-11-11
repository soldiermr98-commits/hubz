<?php
declare(strict_types=1);

namespace App\Core;

/** HTTPS is mandatory for financial information, checkout tokens and gateway credentials. */
final class PaymentSecurity
{
    public static function requireHttps(Request $request, bool $html = false): void
    {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        $secure = $https === 'on' || $https === '1';
        $trusted = Config::get('security.trusted_proxy_ips', []);
        if (!$secure && is_array($trusted) && in_array($request->ip(), $trusted, true)) $secure = strtolower((string)$request->header('X-Forwarded-Proto', '')) === 'https';
        // HTTP is available only to the guarded local disposable fixture.
        if (!$secure && PHP_SAPI === 'cli-server' && getenv('HHX_ALLOW_TEST_FIXTURES') === '1'
            && in_array($request->ip(), ['127.0.0.1','::1'], true)
            && preg_match('/^hhx_test_[a-z0-9_]+$/', (string)Config::get('db.database', ''))) $secure = true;
        if (!$secure) {
            header('Cache-Control: no-store');
            if ($html) Response::html('<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>اتصال امن لازم است</title><body><p>این صفحه فقط با اتصال امن HTTPS قابل استفاده است.</p></body></html>', 400);
            Response::json(['ok'=>false,'error'=>'این بخش فقط با اتصال امن HTTPS قابل استفاده است.'], 400);
        }
    }
}
