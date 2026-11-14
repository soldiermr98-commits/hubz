<?php
declare(strict_types=1);

/**
 * S24 — تست دود لایو مسیرهای اصلی سایت روی هاست واقعی.
 *
 * اجرا از خط فرمان (روی هاست یا هر ماشین با دسترسی به سایت):
 *   php bin/smoke-live.php https://hamihubx.ir
 *
 * بدون تغییر هیچ داده‌ای؛ فقط GET به صفحات عمومی و بررسی نشانه‌های سلامت.
 * خروجی: JSON خلاصه؛ کد خروج ۰ = همه‌چیز سالم، ۱ = حداقل یک مشکل.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$baseUrl = trim((string) ($argv[1] ?? ''));
if ($baseUrl === '' || !preg_match('#^https?://[A-Za-z0-9.\-]+(/.*)?$#', $baseUrl)) {
    fwrite(STDERR, "استفاده: php bin/smoke-live.php https://hamihubx.ir\n");
    exit(1);
}
$baseUrl = rtrim($baseUrl, '/');

/**
 * @return array{status:int,ok:bool,body:string,error:string,duration_ms:int}
 */
function fetchUrl(string $url): array
{
    $started = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'HamiHub-Smoke-Live/1.0 (+launch health check)',
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [
        'status' => $status,
        'ok' => $body !== false && $status >= 200 && $status < 400,
        'body' => is_string($body) ? $body : '',
        'error' => $error,
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
    ];
}

$checks = [];
$failed = 0;

$targets = [
    ['id' => 'home', 'label' => 'صفحهٔ اصلی', 'path' => '/', 'expect' => ['hamihub', 'حامی']],
    ['id' => 'robots', 'label' => 'robots.txt', 'path' => '/robots.txt', 'expect' => ['sitemap', 'user-agent']],
    ['id' => 'sitemap', 'label' => 'sitemap.xml', 'path' => '/sitemap.xml', 'expect' => ['<urlset', '<loc']],
    ['id' => 'admin_login', 'label' => 'صفحهٔ ورود پنل', 'path' => '/admin/', 'expect' => ['ورود', 'admin']],
    ['id' => 'api_summary', 'label' => 'اندپوینت آمار عمومی', 'path' => '/api/v1/analytics/summary', 'expect' => ['ok']],
    ['id' => 'api_city_list', 'label' => 'اندپوینت شهرها', 'path' => '/api/v1/cities', 'expect' => ['ok']],
];

foreach ($targets as $t) {
    $r = fetchUrl($baseUrl . $t['path']);
    $markerFound = true;
    $haystack = mb_strtolower($r['body']);
    foreach ($t['expect'] as $needle) {
        if ($haystack !== '' && str_contains($haystack, mb_strtolower($needle))) {
            $markerFound = true;
            break;
        }
        $markerFound = false;
    }
    $pass = $r['ok'] && $markerFound;
    if (!$pass) $failed++;
    $checks[] = [
        'id' => $t['id'],
        'label' => $t['label'],
        'path' => $t['path'],
        'http_status' => $r['status'],
        'pass' => $pass,
        'duration_ms' => $r['duration_ms'],
        'error' => $r['error'] !== '' ? $r['error'] : (!$markerFound ? 'نشانهٔ مورد انتظار در پاسخ یافت نشد' : ''),
    ];
}

echo json_encode([
    'base_url' => $baseUrl,
    'ok' => $failed === 0,
    'failed_count' => $failed,
    'checks' => $checks,
    'generated_at' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failed === 0 ? 0 : 1);
