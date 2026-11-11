<?php
declare(strict_types=1);

// Temporary, one-shot, read-only diagnostic for Hamihub admin API startup.
// Upload as a temporary file directly inside public_html, run once, then remove.
const HHX_DIAGNOSTIC_TOKEN_SHA256 = '9eeca4bf2534f1659f73fdb360f301ccf01ca50f0055ef0f5b7e19ee99afd066';
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

function hhxDiagEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hhxDiagPage(string $content, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>بررسی موقت API حامی‌هاب</title><body style="font:15px sans-serif;max-width:760px;margin:40px auto;padding:0 16px;line-height:2">' . $content . '</body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    hhxDiagPage('<h1>بررسی موقت API پنل</h1><p>این ابزار فقط چند مورد فنی را بررسی می‌کند؛ تنظیمات، رمزها و محتوای دیتابیس را نمایش نمی‌دهد و هیچ مایگریشنی اجرا نمی‌کند.</p><form method="post"><label>کد یک‌بارمصرف را وارد کن: <input name="access_code" type="password" autocomplete="off" required style="width:100%;padding:10px;box-sizing:border-box"></label><p><button type="submit">اجرای بررسی یک‌باره</button></p></form><p>پس از اجرا، این فایل خودش را حذف می‌کند. اگر حذف خودکار نشد، آن را بلافاصله از File Manager پاک کن.</p>');
}

$provided = is_string($_POST['access_code'] ?? null) ? trim($_POST['access_code']) : '';
if ($provided === '' || !hash_equals(HHX_DIAGNOSTIC_TOKEN_SHA256, hash('sha256', $provided))) {
    hhxDiagPage('<h1>کد نادرست است</h1><p>برای حفظ امنیت، جزئیات بررسی نمایش داده نشد.</p>', 403);
}

$report = [];
$run = static function (string $label, callable $check) use (&$report): bool {
    try {
        $detail = $check();
        $report[] = ['label'=>$label,'ok'=>true,'detail'=>(string)$detail];
        return true;
    } catch (Throwable $error) {
        $report[] = ['label'=>$label,'ok'=>false,'detail'=>get_class($error) . ' in ' . basename($error->getFile()) . ':' . $error->getLine()];
        return false;
    }
};

$root = dirname(__DIR__);
$report[] = ['label'=>'PHP runtime','ok'=>true,'detail'=>PHP_VERSION];
$report[] = ['label'=>'Extensions','ok'=>true,'detail'=>'pdo=' . (extension_loaded('pdo') ? 'yes' : 'no') . ', pdo_mysql=' . (extension_loaded('pdo_mysql') ? 'yes' : 'no') . ', mbstring=' . (extension_loaded('mbstring') ? 'yes' : 'no')];

$run('Project files', static function () use ($root): string {
    foreach (['app/Core/Bootstrap.php','app/Core/Router.php','app/Core/Auth.php','app/Controllers/AuthController.php','app/Controllers/BusinessController.php','public_html/api/index.php'] as $relative) {
        if (!is_file($root . '/' . $relative)) throw new RuntimeException('A required project file is missing.');
    }
    return 'required files found';
});

$syntaxOk = true;
foreach (['app/Core/Bootstrap.php','app/Core/Router.php','app/Core/Auth.php','app/Controllers/AuthController.php','app/Controllers/BusinessController.php','public_html/api/index.php'] as $relative) {
    $syntaxOk = $run('PHP syntax: ' . basename($relative), static function () use ($root, $relative): string {
        $source = file_get_contents($root . '/' . $relative);
        if (!is_string($source)) throw new RuntimeException('A project file could not be read.');
        token_get_all($source, TOKEN_PARSE);
        return 'syntax parsed';
    }) && $syntaxOk;
}

$bootOk = $run('Application config/bootstrap', static function () use ($root): string {
    require_once $root . '/app/Core/Bootstrap.php';
    \App\Core\Bootstrap::init($root);
    return 'loaded';
});

if ($bootOk) {
    $run('API route callbacks', static function (): string {
        $router = new \App\Core\Router();
        $router->get('/api/v1/auth/me', [\App\Controllers\AuthController::class, 'me']);
        $router->post('/api/v1/businesses/publish', [\App\Controllers\BusinessController::class, 'publishSaved']);
        return 'auth/me and admin publish callbacks are callable';
    });

    $run('PHP session storage', static function (): string {
        $oldCookies = (string)ini_get('session.use_cookies');
        if (session_status() === PHP_SESSION_ACTIVE) throw new RuntimeException('A session was already active.');
        if (ini_set('session.use_cookies', '0') === false) throw new RuntimeException('Could not isolate the diagnostic session.');
        $name = (string)\App\Core\Config::get('security.session_name', 'hhx_session');
        if ($name === '') $name = 'hhx_session';
        session_name($name);
        if (!session_start()) throw new RuntimeException('Session could not start.');
        $id = session_id();
        $_SESSION['__hhx_diagnostic_probe'] = 'temporary';
        session_write_close();
        session_id($id);
        session_start();
        session_destroy();
        ini_set('session.use_cookies', $oldCookies);
        return 'temporary isolated session write/read/delete passed';
    });

    $run('Database connectivity (read-only)', static function (): string {
        $row = \App\Core\DB::fetchOne('SELECT 1 AS diagnostic_ok');
        if ((int)($row['diagnostic_ok'] ?? 0) !== 1) throw new RuntimeException('Read-only database probe did not return 1.');
        return 'read-only SELECT 1 passed';
    });
}

$allOk = !array_filter($report, static fn(array $item): bool => !$item['ok']);
$removed = @unlink(__FILE__);
$rows = '';
foreach ($report as $item) {
    $symbol = $item['ok'] ? '✅' : '❌';
    $rows .= '<li>' . $symbol . ' <strong>' . hhxDiagEscape($item['label']) . ':</strong> ' . hhxDiagEscape($item['detail']) . '</li>';
}
$rows .= '<li>' . ($removed ? '✅' : '⚠️') . ' <strong>حذف فایل دیباگ:</strong> ' . ($removed ? 'انجام شد' : 'خودکار انجام نشد؛ همین حالا فایل را از File Manager حذف کن') . '</li>';
$message = $allOk
    ? '<p><strong>بررسی‌های پایه موفق شدند.</strong> این نتیجه علت هر خطای PHP-FPM یا تنظیم وب‌سرور را به‌تنهایی رد نمی‌کند.</p>'
    : '<p><strong>یک یا چند بررسی شکست خورد.</strong> همین گزارش محدود را برای پشتیبانی هاست بفرست؛ هیچ مقدار تنظیمات یا رمز در آن نیست.</p>';
hhxDiagPage('<h1>گزارش بررسی یک‌باره</h1>' . $message . '<ul>' . $rows . '</ul><p>کد دسترسی و اطلاعات ورود را برای کسی نفرست.</p>');
