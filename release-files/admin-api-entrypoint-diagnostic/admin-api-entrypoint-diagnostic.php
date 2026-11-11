<?php
declare(strict_types=1);

// One-shot, token-protected probe that dispatches the real /api/v1/auth/me route.
// Upload temporarily to public_html, execute once, then remove.
const HHX_ENTRYPOINT_DIAG_TOKEN_SHA256 = '7ce98ac4474efbedd4245868c945566180da20577239f4c3e17d808db1f31892';
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

function hhxEntryEscape(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hhxEntryPage(string $html, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>بررسی API حامی‌هاب</title><body style="font:15px sans-serif;max-width:760px;margin:40px auto;padding:0 16px;line-height:2">' . $html . '</body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    hhxEntryPage('<h1>بررسی واقعی مسیر API پنل</h1><p>این ابزار فایل اصلی API را با درخواست GET به مسیر auth/me اجرا می‌کند. بدنهٔ پاسخ و توکن CSRF نمایش داده نمی‌شوند؛ این تست هیچ مایگریشن یا تغییری در جدول‌های برنامه انجام نمی‌دهد.</p><form method="post"><label>کد یک‌بارمصرف: <input name="access_code" type="password" autocomplete="off" required style="width:100%;padding:10px;box-sizing:border-box"></label><p><button type="submit">اجرای تست</button></p></form><p>پس از اجرای کد معتبر، فایل خودش را حذف می‌کند. اگر حذف نشد، فوراً از File Manager پاکش کن.</p>');
}

$provided = is_string($_POST['access_code'] ?? null) ? trim($_POST['access_code']) : '';
if ($provided === '' || !hash_equals(HHX_ENTRYPOINT_DIAG_TOKEN_SHA256, hash('sha256', $provided))) {
    hhxEntryPage('<h1>کد نادرست است</h1><p>جزئیاتی اجرا یا نمایش داده نشد.</p>', 403);
}

$root = dirname(__DIR__);
$entryFile = $root . '/public_html/api/index.php';
if (!is_file($entryFile)) {
    hhxEntryPage('<h1>تست متوقف شد</h1><p>فایل اصلی API در مسیر مورد انتظار پیدا نشد. فایل دیباگ را از هاست حذف کن و این پیام را گزارش بده.</p>', 500);
}

$oldCookies = (string)ini_get('session.use_cookies');
if (ini_set('session.use_cookies', '0') === false) {
    hhxEntryPage('<h1>تست متوقف شد</h1><p>نتوانستم کوکی نشست تشخیصی را غیرفعال کنم. فایل را از هاست حذف کن و این پیام را گزارش بده.</p>', 500);
}

// Isolate the unauthenticated probe from the browser's real admin session and inputs.
$_POST = [];
$_GET = [];
$_REQUEST = [];
$_COOKIE = [];
unset($_SERVER['HTTP_COOKIE']);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_id('hxd' . bin2hex(random_bytes(12)));
}

$caught = null;
$startObLevel = ob_get_level();
ob_start();

register_shutdown_function(static function () use ($oldCookies, &$caught, $startObLevel): void {
    $chunks = [];
    while (ob_get_level() > $startObLevel) {
        $chunks[] = (string)ob_get_clean();
    }
    $body = implode('', array_reverse($chunks));
    $status = http_response_code();
    $decoded = json_decode($body, true);
    $last = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $fatal = is_array($last) && in_array((int)($last['type'] ?? 0), $fatalTypes, true);

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        @session_destroy();
    }
    @ini_set('session.use_cookies', $oldCookies);

    if (!headers_sent()) {
        header_remove('Set-Cookie');
        header('Cache-Control: no-store, private, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(200);
    }

    $deleted = @unlink(__FILE__);

    if ($caught instanceof Throwable) {
        $apiCaller = '';
        foreach ($caught->getTrace() as $frame) {
            $frameFile = isset($frame['file']) && is_string($frame['file']) ? basename($frame['file']) : '';
            $frameLine = isset($frame['line']) ? (int)$frame['line'] : 0;
            if ($frameFile !== '' && $frameLine > 0) {
                $apiCaller = $frameFile . ':' . (string)$frameLine;
                if ($frameFile === 'index.php') {
                    break;
                }
            }
        }
        $message = '<p><strong>استثنا/خطای PHP هنگام اجرای API گرفته شد.</strong></p><ul>' .
            '<li>کلاس خطا: ' . hhxEntryEscape(get_class($caught)) . '</li>' .
            '<li>محل وقوع: ' . hhxEntryEscape(basename($caught->getFile()) . ':' . (string)$caught->getLine()) . '</li>' .
            ($apiCaller !== '' ? '<li>فراخوان مرتبط: ' . hhxEntryEscape($apiCaller) . '</li>' : '') .
            '</ul><p>متن کامل خطا عمداً نمایش داده نشد تا اطلاعات تنظیمات یا دیتابیس فاش نشود.</p>';
    } elseif (is_array($decoded) && array_key_exists('ok', $decoded)) {
        $ok = !empty($decoded['ok']) ? 'true' : 'false';
        $authenticated = array_key_exists('authenticated', $decoded) ? (!empty($decoded['authenticated']) ? 'true' : 'false') : 'not-returned';
        $csrfPresent = !empty($decoded['csrf_token']) ? 'yes (value hidden)' : 'no';
        $message = '<p><strong>فایل API اجرا شد و پاسخ JSON برگرداند.</strong></p><ul>' .
            '<li>HTTP داخل API: ' . hhxEntryEscape((string)$status) . '</li>' .
            '<li>ok: ' . hhxEntryEscape($ok) . '</li>' .
            '<li>authenticated در نشست موقت: ' . hhxEntryEscape($authenticated) . '</li>' .
            '<li>CSRF token: ' . hhxEntryEscape($csrfPresent) . '</li></ul>';
    } elseif ($fatal) {
        $message = '<p><strong>Fatal PHP هنگام اجرای API گرفته شد.</strong></p><ul>' .
            '<li>نوع خطا: ' . hhxEntryEscape((string)$last['type']) . '</li>' .
            '<li>فایل: ' . hhxEntryEscape(basename((string)$last['file'])) . '</li>' .
            '<li>خط: ' . hhxEntryEscape((string)$last['line']) . '</li></ul>' .
            '<p>متن کامل خطا عمداً نمایش داده نشد تا اطلاعات تنظیمات یا دیتابیس فاش نشود.</p>';
    } else {
        $message = '<p><strong>اجرای API پاسخی با قالب JSON نداد.</strong></p><ul>' .
            '<li>HTTP داخل API: ' . hhxEntryEscape((string)$status) . '</li>' .
            '<li>بایت خروجی گرفته‌شده: ' . hhxEntryEscape((string)strlen($body)) . '</li>' .
            '<li>خطای آخر PHP: ' . ($last ? hhxEntryEscape(basename((string)$last['file']) . ':' . (string)$last['line'] . ' (type ' . (string)$last['type'] . ')') : 'ثبت نشد') . '</li></ul>';
    }
    $message .= '<p><strong>حذف فایل موقت:</strong> ' . ($deleted ? 'انجام شد' : 'خودکار انجام نشد؛ همین حالا فایل را دستی پاک کن') . '</p>';
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>نتیجهٔ تست API</title><body style="font:15px sans-serif;max-width:760px;margin:40px auto;padding:0 16px;line-height:2"><h1>نتیجهٔ اجرای واقعی API</h1>' . $message . '<p>هیچ مقدار CSRF، رمز، تنظیمات یا متن خام پاسخ نمایش داده نشده است.</p></body></html>';
});

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/v1/auth/me';
$_SERVER['QUERY_STRING'] = '';
$_SERVER['SCRIPT_NAME'] = '/api/index.php';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = (string)($_SERVER['HTTP_HOST'] ?? 'hamihubx.ir');

try {
    require $entryFile;
} catch (Throwable $error) {
    $caught = $error;
}
