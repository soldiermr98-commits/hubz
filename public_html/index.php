<?php
declare(strict_types=1);

/**
 * نقطه ورود اصلی وب‌سایت عمومی HamiHubX.ir (مرحله S16)
 * شامل سرو مستقیم robots.txt و sitemap.xml، کش HTML دیسک، قالب‌های سئو، اسکیماهای JSON-LD،
 * جستجوی داخلی (/search)، فهرست کامل کسب‌وکارها (/businesses)، صفحه ثبت دیدگاه (/comment/{city}/{slug})
 * و «سپر خطای سراسری» برای نمایش صفحه فارسی مهربان به‌جای HTTP 500 خالی هاست
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Core/Bootstrap.php';

use App\Controllers\PublicSiteController;
use App\Core\Bootstrap;
use App\Core\Cache;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

Bootstrap::init(BASE_PATH);

/**
 * S16 — سپر خطای سراسری:
 * اگر هر استثنا یا خطای مهلکی در چرخه رندر رخ دهد، به‌جای «This page isn't working (HTTP 500)»
 * صفحه‌ای فارسی، سبک و بدون ارجاع خارجی نمایش داده می‌شود و متن خطا فقط در error_log هاست می‌رود.
 */
final class HXErrorShield
{
    private static bool $responded = false;

    public static function markResponded(): void
    {
        self::$responded = true;
    }

    public static function render(bool $isApi): void
    {
        if (self::$responded) return;
        $last = function_exists('error_get_last') ? error_get_last() : null;
        if ($last === null) return; // shutdown عادی؛ چیزی نمایش داده نشود
        $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if ((($last['type'] ?? 0) & $fatalTypes) === 0) {
            return; // خطای غیرمهلک که مدیریت شده
        }
        error_log('HX fatal: ' . (string)($last['message'] ?? '') . ' @ ' . (string)($last['file'] ?? '?') . ':' . (string)($last['line'] ?? '?'));
        if (headers_sent()) return;
        self::$responded = true;
        http_response_code(500);
        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');
            echo '{"ok":false,"error":"خطای داخلی سرور؛ چند لحظه بعد دوباره تلاش کنید."}';
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, follow');
        header('Retry-After: 60');
        $css = "@font-face{font-family:Vazirmatn;font-display:swap;src:url('/assets/fonts/Vazirmatn-Regular.woff2') format('woff2');}"
            . '*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:Vazirmatn,system-ui,sans-serif;background:#f6f8fb;color:#10131a;padding:1.25rem}'
            . '.card{max-width:520px;width:100%;background:#fff;border:1px solid #e3e8ef;border-radius:16px;box-shadow:0 18px 44px -20px rgba(4,8,20,.25);padding:2.25rem 1.5rem;text-align:center}'
            . '.code{font-size:2.4rem;font-weight:800;line-height:1;background:linear-gradient(135deg,#09132e,#0047cc);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}'
            . 'h1{font-size:1.15rem;margin:.9rem 0 .5rem}p{font-size:.9rem;color:#5c647a;line-height:1.9;margin:.35rem 0 1.4rem}'
            . '.btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;border-radius:10px;padding:.65rem 1.4rem;font-weight:700;font-size:.9rem}';
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>خطای موقت | حامی‌هاب گیلان</title><style>' . $css . '</style></head><body>'
            . '<div class="card"><div class="code" dir="ltr">۵۰۰</div>'
            . '<h1>یک خطای موقت در سرور رخ داد</h1>'
            . '<p>صفحه‌های دیگر سایت سالم هستند. چند لحظه صبر کنید و دوباره تلاش کنید؛'
            . 'اگر مشکل تکرار شد از صفحه اصلی مسیر مورد نظر را باز کنید.</p>'
            . '<a class="btn" href="/">بازگشت به صفحه اصلی حامی‌هاب</a>'
            . '</div></body></html>';
    }
}

register_shutdown_function(static function (): void {
    $isApi = str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/');
    HXErrorShield::render($isApi);
});

$request = new Request();
$path = $request->path();

// ۱. سرو فوری robots.txt و sitemap.xml قبل از هرگونه کش یا روتر برای تضمین صفر خطای 404
if ($path === '/robots.txt') {
    PublicSiteController::robotsTxt($request);
    exit;
}
if ($path === '/sitemap.xml') {
    PublicSiteController::sitemapXml($request);
    exit;
}

// ۲. اگر درخواست مربوط به مسیرهای API بود به کنترلر API هدایت شود
if (str_starts_with($path, '/api/')) {
    require BASE_PATH . '/public_html/api/index.php';
    exit;
}

// ۳. اگر درخواست مربوط به زیرمسیرهای پنل مدیریت (/admin/review, /admin/articles و غیره) بود، فایل SPA پنل سرو شود
if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    $adminIndexFile = __DIR__ . '/admin/index.html';
    if (is_file($adminIndexFile)) {
        Response::html((string) file_get_contents($adminIndexFile));
    }
}

// Only current-version public pages may be served from the disk cache.
if ($request->method() === 'GET' && empty($_GET)) {
    $cachedHtml = Cache::getPage($path);
    if ($cachedHtml !== null) {
        HXErrorShield::markResponded();
        Response::html($cachedHtml);
    }
    // Capture before the router or controller reads data. A later purge makes
    // this render ineligible to write the cache, even if it finishes last.
    Cache::beginRender();
}

$router = new Router();

// ۵. مسیرهای عمومی سئو و قالب‌های S4 + مسیرهای جدید S16
$router->get('/', [PublicSiteController::class, 'home']);
$router->get('/search', [PublicSiteController::class, 'searchPage']);
$router->get('/businesses', [PublicSiteController::class, 'businessesCatalog']);
$router->get('/advertise', [PublicSiteController::class, 'advertisePage']); // S19: قبل از catch-all های /{slug}
$router->get('/comment/{city}/{slug}', [PublicSiteController::class, 'commentPage']);
$router->get('/sitemap.xml', [PublicSiteController::class, 'sitemapXml']);
$router->get('/robots.txt', [PublicSiteController::class, 'robotsTxt']);
$router->get('/b/{slug}', [PublicSiteController::class, 'legacyBusinessDetail']);
$router->get('/city/{city}', [PublicSiteController::class, 'cityHub']);
$router->get('/city/{city}/{category}', [PublicSiteController::class, 'cityCategory']);
$router->get('/{city}/{slug}', [PublicSiteController::class, 'businessDetail']);
$router->get('/{slug}', [PublicSiteController::class, 'articleDetail']);

try {
    $router->dispatch($request);
} catch (Throwable $e) {
    // هرگز صفحه نیمه‌سوخته یا HTTP 500 خالی نشان نده؛ لاگ کامل در error_log هاست می‌ماند.
    error_log('HX dispatch: [' . get_class($e) . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . ' for "' . $request->method() . ' ' . $path . '"');
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, follow');
        header('Retry-After: 60');
    }
    PublicSiteController::renderShieldPage();
}
