<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\PaymentSecurity;
use App\Core\PaymentSandbox;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdvertisingFinanceService;
use App\Services\AdvertisingOnlinePaymentService;
use App\Services\PaymentGatewayClient;
use App\Services\PaymentGatewaySettings;
use Throwable;

/** Public bearer links contain no private credential; callbacks never trust browser claims. */
final class PublicPaymentController
{
    private static function headers(Request $request): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        if (!PaymentSandbox::enabled()) self::page('پرداخت آزمایشی فعال نیست', '<p>این صفحه در دسترس نیست.</p>', 404);
        PaymentSecurity::requireHttps($request, true);
    }
    private static function service(): AdvertisingOnlinePaymentService
    {
        return new AdvertisingOnlinePaymentService(new PaymentGatewaySettings(BASE_PATH), new PaymentGatewayClient(), true);
    }
    private static function page(string $heading, string $body, int $status = 200): never
    {
        Response::sendSecurityHeaders(true);
        // The bearer token is in the URL; never leak it via same-origin Referer.
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Response::e($heading) . ' | حامی‌هاب</title>' .
            '<style>body{font:16px/1.9 Tahoma,sans-serif;background:#f5f7f7;color:#1c3431;padding:20px}main{max-width:620px;margin:5vh auto;padding:28px;background:white;border:1px solid #d9e5e1;border-radius:18px}h1{font-size:1.4rem}button{border:0;border-radius:10px;background:#176f60;color:#fff;font:inherit;padding:10px 22px;cursor:pointer}.note{background:#fff4d9;padding:12px;border-radius:9px}</style></head><body><main><h1>' . Response::e($heading) . '</h1>' . $body . '<p><a href="/">بازگشت به سایت</a></p></main></body></html>';
        exit;
    }
    private static function fail(): never
    {
        self::page('پرداخت قابل نمایش نیست', '<p>لینک یا وضعیت تراکنش قابل بررسی نیست. از پرداخت مجدد خودداری کنید و با پشتیبانی تماس بگیرید.</p>', 409);
    }
    public static function view(Request $request, array $params): void
    {
        self::headers($request);
        try {
            if (!AdvertisingFinanceService::schemaReady()) self::fail();
            $token = (string)($params['token'] ?? ''); $p = AdvertisingOnlinePaymentService::publicView($token);
            if (!$p || $p['mode'] !== 'sandbox') self::fail();
            $provider = PaymentGatewaySettings::PROVIDERS[$p['provider']] ?? 'درگاه';
            $body = '<p>قرارداد: <strong>' . Response::e((string)$p['title']) . '</strong></p><p>مبلغ: <strong>' . number_format((int)$p['amount_toman']) . ' تومان</strong></p><p>درگاه: ' . Response::e($provider) . '</p>';
            if ($p['mode'] === 'sandbox') $body .= '<p class="note"><strong>این تراکنش آزمایشی است؛ وجه واقعی دریافت نمی‌شود و مانده واقعی تغییر نمی‌کند.</strong></p>';
            if (($p['state'] === 'link_ready' && (int)$p['unexpired']) || $p['state'] === 'pending') {
                $body .= '<p>با ادامه، فقط برای همین تراکنش به درگاه منتقل می‌شوید.</p><form method="post" action="/api/v1/public/advertising-payment/' . Response::e($token) . '"><input type="hidden" name="_csrf" value="' . Response::e(Csrf::token()) . '"><button type="submit">ادامه به درگاه</button></form>';
            } elseif (in_array($p['state'], ['verified','reconciled'], true)) {
                $body .= '<p>این تراکنش قبلاً ثبت شده است. برای جزئیات با پشتیبانی تماس بگیرید.</p>';
            } elseif (in_array($p['state'], ['sandbox_verified','sandbox_reconciled'], true)) {
                $body .= '<p>تراکنش آزمایشی پایان یافته است و پرداخت واقعی محسوب نمی‌شود.</p>';
            } else {
                $body .= '<p class="note">این لینک قابل شروع نیست یا نتیجه در دست بررسی است. پیش از اقدام دوباره با پشتیبانی تماس بگیرید.</p>';
            }
            self::page('پرداخت قرارداد تبلیغات', $body);
        } catch (Throwable $e) { self::fail(); }
    }
    public static function start(Request $request, array $params): void
    {
        self::headers($request); Csrf::verifyOrFail($request);
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        try {
            if (!AdvertisingFinanceService::schemaReady()) self::fail();
            $url = self::service()->checkout((string)($params['token'] ?? ''));
            // URL is constructed from a fixed provider host and validated ID, never a browser input.
            Response::sendSecurityHeaders(false);
            header('Cache-Control: private, no-store, max-age=0');
            // Aqaye Pardakht requires the merchant origin in the Referer, without disclosing the bearer token.
            header('Referrer-Policy: origin');
            header('Location: ' . $url, true, 303);
            exit;
        } catch (Throwable $e) { self::fail(); }
    }
    public static function callback(Request $request, array $params): void
    {
        self::headers($request);
        try {
            if (!AdvertisingFinanceService::schemaReady()) self::fail();
            $input = [];
            foreach (['trackId','success','transid','invoice_id','status','id','order_id'] as $name) $input[$name] = $request->input($name);
            $state = self::service()->callback((string)($params['token'] ?? ''), $input);
            if ($state === 'verified') self::page('پرداخت تأیید شد', '<p>درگاه پرداخت را تأیید کرد. برای جزئیات با پشتیبانی تماس بگیرید.</p>');
            if ($state === 'reconciled') self::page('تراکنش ثبت شده است', '<p>وضعیت این پرداخت پیش‌تر پس از بررسی مدیر ثبت شده است.</p>');
            if (in_array($state, ['sandbox_verified','sandbox_reconciled'], true)) self::page('پایان تراکنش آزمایشی', '<p>این نتیجه آزمایشی است؛ وجه واقعی دریافت نشده و مانده قرارداد تغییر نکرده است.</p>');
            self::page('پرداخت در انتظار بررسی', '<p class="note">پرداخت تأیید نشده یا نتیجه هنوز روشن نیست. از پرداخت مجدد خودداری کنید و با پشتیبانی تماس بگیرید.</p>');
        } catch (Throwable $e) { self::fail(); }
    }
}
