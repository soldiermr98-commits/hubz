<?php
declare(strict_types=1);

/**
 * ابزار بررسی سلامت محیط هاست اشتراکی DirectAdmin (check.php)
 * علاوه بر بررسی نسخه PHP، افزونه‌ها، مجوز نوشتن پوشه‌ها و دستور Cron،
 * امکان اجرای مایگریشن‌ها با پارامتر ?key=...&migrate=1 را بدون نیاز به SSH فراهم می‌کند.
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Core/Bootstrap.php';

use App\Core\Bootstrap;
use App\Core\Config;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Response;

Bootstrap::init(BASE_PATH);

$expectedKey = (string) Config::get('app.check_key', '');
$providedKey = trim((string) ($_GET['key'] ?? ''));

if (PHP_SAPI !== 'cli' && (strlen($expectedKey) < 24 || !hash_equals($expectedKey, $providedKey))) {
    Response::json([
        'ok' => false,
        'error' => 'کلید دسترسی به گزارش سرور (check_key) نامعتبر است. کلید را با ?key=... ارسال کنید.',
    ], 403);
}

function detectPhpCliBinary(): string
{
    $candidates = [
        '/usr/local/php83/bin/php',
        '/usr/local/php82/bin/php',
        '/usr/local/php81/bin/php',
        '/usr/local/bin/php',
        '/usr/bin/php',
        PHP_BINARY,
    ];

    foreach ($candidates as $path) {
        if ($path !== '' && @is_executable($path)) {
            return $path;
        }
    }

    return '/usr/local/bin/php';
}

$gdInfo = function_exists('gd_info') ? gd_info() : [];
$webpSupported = !empty($gdInfo['WebP Support']);

$writableDirs = [
    'public_html/cache/pages' => BASE_PATH . '/public_html/cache/pages',
    'public_html/uploads' => BASE_PATH . '/public_html/uploads',
    'storage/logs' => BASE_PATH . '/storage/logs',
];

$dirChecks = [];
foreach ($writableDirs as $label => $fullPath) {
    if (!is_dir($fullPath)) {
        @mkdir($fullPath, 0755, true);
    }
    $dirChecks[$label] = [
        'path' => $fullPath,
        'exists' => is_dir($fullPath),
        'writable' => is_writable($fullPath),
    ];
}

$dbStatus = [
    'connected' => false,
    'version' => null,
    'time_zone' => null,
    'migrations' => null,
    'error' => null,
];

try {
    $versionRow = DB::fetchOne("SELECT VERSION() AS ver, @@session.time_zone AS tz");
    $dbStatus['connected'] = true;
    $dbStatus['version'] = $versionRow['ver'] ?? 'unknown';
    $dbStatus['time_zone'] = $versionRow['tz'] ?? 'unknown';

    // Migrations are only executed through the authenticated admin endpoint or installer.
} catch (Throwable $e) {
    $dbStatus['error'] = $e->getMessage();
}

$phpCli = detectPhpCliBinary();
$workerPath = BASE_PATH . '/cron/worker.php';
$cronCommand = sprintf('*/5 * * * * %s -q %s >/dev/null 2>&1', $phpCli, $workerPath);

$report = [
    'ok' => $dbStatus['connected'] && version_compare(PHP_VERSION, '8.1.0', '>=') && extension_loaded('pdo_mysql') && extension_loaded('mbstring') && extension_loaded('curl'),
    'timestamp_tehran' => date('Y-m-d H:i:s P'),
    'php' => [
        'version' => PHP_VERSION,
        'version_ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'sapi' => PHP_SAPI,
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'timezone' => date_default_timezone_get(),
        'argon2id_supported' => defined('PASSWORD_ARGON2ID'),
        'password_algo_active' => defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'Bcrypt (Fallback)',
    ],
    'extensions' => [
        'gd' => extension_loaded('gd'),
        'gd_webp' => $webpSupported,
        'mbstring' => extension_loaded('mbstring'),
        'curl' => extension_loaded('curl'),
        'intl' => extension_loaded('intl'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
    ],
    'directories' => $dirChecks,
    'database' => $dbStatus,
    'directadmin_cron' => [
        'detected_php_cli' => $phpCli,
        'worker_script' => $workerPath,
        'recommended_cron_line' => $cronCommand,
    ],
];

if (PHP_SAPI === 'cli' || (isset($_GET['format']) && $_GET['format'] === 'json')) {
    Response::json($report);
}

$html = '<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>گزارش بررسی سلامت سرور — HamiHubX.ir</title>
<style>
body{font-family:Tahoma,sans-serif;background:#f8fafc;color:#0f172a;padding:2rem;line-height:1.7;}
.card{max-width:860px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1.5rem;}
table{width:100%;border-collapse:collapse;margin-top:1rem;}
th,td{border-bottom:1px solid #e2e8f0;padding:.6rem;text-align:right;font-size:.9rem;}
code{background:#f1f5f9;padding:.2rem .4rem;border-radius:4px;direction:ltr;display:inline-block;}
.pass{color:#047857;font-weight:bold;}
.fail{color:#b91c1c;font-weight:bold;}
.btn{display:inline-block;padding:.5rem 1rem;background:#047857;color:#fff;text-decoration:none;border-radius:6px;font-size:.85rem;font-weight:bold;}
</style>
</head>
<body>
<div class="card">
  <h1>گزارش بررسی محیط هاست اشتراکی DirectAdmin (check.php)</h1>
  <p>زمان سرور (تهران): <code>' . Response::e($report['timestamp_tehran']) . '</code>
  &nbsp; | &nbsp;
  <a class="btn" href="?key=' . Response::e($providedKey) . '&migrate=1">اجرای مایگریشن‌های دیتابیس (بدون ترمینال)</a></p>';

if (is_array($dbStatus['migrations'])) {
    $html .= '<div style="background:#ecfdf5;color:#047857;padding:.75rem;border-radius:6px;margin-bottom:1rem;font-size:.9rem;">
      ✅ مایگریشن‌های دیتابیس بررسی شدند. تعداد فایل جدید اعمال‌شده: <strong>' . (int) $dbStatus['migrations']['total_applied'] . '</strong>
      (قبلاً اجرا شده: ' . count($dbStatus['migrations']['skipped']) . ')
    </div>';
}

$html .= '<table>
    <tr><th>مورد بررسی</th><th>وضعیت / مقدار</th></tr>
    <tr><td>نسخه PHP (حداقل 8.1 / پیشنهادی 8.3)</td><td class="' . ($report['php']['version_ok'] ? 'pass' : 'fail') . '"><code>' . Response::e($report['php']['version']) . '</code></td></tr>
    <tr><td>الگوریتم هش رمز عبور</td><td class="pass">' . Response::e($report['php']['password_algo_active']) . '</td></tr>
    <tr><td>افزونه‌های PHP (gd, webp, mbstring, curl, intl, pdo_mysql)</td><td>
      gd: ' . ($report['extensions']['gd'] ? '✅' : '❌') . ' |
      webp: ' . ($report['extensions']['gd_webp'] ? '✅' : '❌') . ' |
      mbstring: ' . ($report['extensions']['mbstring'] ? '✅' : '❌') . ' |
      curl: ' . ($report['extensions']['curl'] ? '✅' : '❌') . ' |
      intl: ' . ($report['extensions']['intl'] ? '✅' : '❌') . ' |
      pdo_mysql: ' . ($report['extensions']['pdo_mysql'] ? '✅' : '❌') . '
    </td></tr>
    <tr><td>memory_limit / max_execution_time</td><td><code>' . Response::e((string) $report['php']['memory_limit']) . '</code> / <code>' . Response::e((string) $report['php']['max_execution_time']) . 's</code></td></tr>
    <tr><td>اتصال MySQL/MariaDB و منطقه زمانی</td><td class="' . ($report['database']['connected'] ? 'pass' : 'fail') . '">' .
      ($report['database']['connected']
        ? 'متصل — نسخه: <code>' . Response::e((string) $report['database']['version']) . '</code> | منطقه زمانی: <code>' . Response::e((string) $report['database']['time_zone']) . '</code>'
        : 'خطا: ' . Response::e((string) $report['database']['error'])) . '</td></tr>';

foreach ($report['directories'] as $dirLabel => $dirState) {
    $html .= '<tr><td>مجوز نوشتن پوشه <code>' . Response::e($dirLabel) . '</code></td><td class="' . ($dirState['writable'] ? 'pass' : 'fail') . '">' . ($dirState['writable'] ? 'قابل نوشتن (Writable)' : 'عدم دسترسی نوشتن') . '</td></tr>';
}

$html .= '<tr><td>دستور آماده Cron برای DirectAdmin (هر ۵ دقیقه)</td><td><code>' . Response::e($report['directadmin_cron']['recommended_cron_line']) . '</code></td></tr>
  </table>
</div>
</body>
</html>';

Response::html($html);
