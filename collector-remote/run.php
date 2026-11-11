<?php
declare(strict_types=1);

/**
 * برنامه مستقل collector-remote برای اجرا روی کامپیوتر شخصی شما (مرحله S2)
 * نحوه اجرا روی کامپیوتر شما:
 *   php collector-remote/run.php
 *
 * چرخه کار (مدل Pull):
 * ۱. GET /api/v1/collector/jobs/next (با Bearer Token و CONNECTTIMEOUT=5, TIMEOUT=12)
 * ۲. اجرای Job با ماژول پرووایدر مربوطه (gosom مدل async، osm با TIMEOUT=90، neshan، csv)
 * ۳. ارسال دسته‌ای نتایج در بسته‌های حداکثر ۲۰۰ آیتمی و زیر ۱ مگابایت به POST /api/v1/collector/ingest
 * ۴. در صورتی که پرووایدر خطای 403/429/کپچا بدهد، ارسال گزارش خطا به سرور تا پرووایدر ۲۴ ساعت متوقف شود و به پرووایدر بعدی Fallback کند.
 */

require_once __DIR__ . '/Providers/ProviderInterface.php';
require_once __DIR__ . '/Providers/OsmProvider.php';
require_once __DIR__ . '/Providers/GosomProvider.php';
require_once __DIR__ . '/Providers/CsvProvider.php';
require_once __DIR__ . '/Providers/NeshanProvider.php';
require_once __DIR__ . '/Providers/WikidataProvider.php';
require_once __DIR__ . '/Providers/GooglePlacesProvider.php';
require_once __DIR__ . '/Providers/BaladProvider.php';

use CollectorRemote\Providers\BaladProvider;
use CollectorRemote\Providers\CsvProvider;
use CollectorRemote\Providers\GooglePlacesProvider;
use CollectorRemote\Providers\GosomProvider;
use CollectorRemote\Providers\NeshanProvider;
use CollectorRemote\Providers\OsmProvider;
use CollectorRemote\Providers\ProviderInterface;
use CollectorRemote\Providers\WikidataProvider;

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    $configFile = __DIR__ . '/config.sample.php';
}
$config = require $configFile;

$serverUrl = rtrim((string) ($config['server']['base_url'] ?? 'https://hamihubx.ir'), '/');
$bearerToken = trim((string) ($config['server']['bearer_token'] ?? ''));
$connectTimeout = (int) ($config['server']['connect_timeout'] ?? 5);
$serverTimeout = (int) ($config['server']['timeout'] ?? 12);
$batchSize = min(200, max(1, (int) ($config['server']['batch_size'] ?? 200)));

/**
 * ارسال درخواست JSON به سرور هاست با رعایت CONNECTTIMEOUT=5 و TIMEOUT=12
 */
function callHostApi(
    string $method,
    string $url,
    string $bearerToken,
    int $connectTimeout,
    int $timeout,
    ?array $payload = null
): array {
    $ch = curl_init($url);
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $bearerToken,
    ];

    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $timeout,
    ];

    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        // اطمینان از اینکه حجم بسته ارسالی کمتر از ۱ مگابایت است
        if (strlen((string) $json) > 1000 * 1024) {
            throw new RuntimeException('حجم JSON ارسالی بیش از ۱ مگابایت است.');
        }
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = $json;
    }

    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("خطای ارتباط cURL با سرور هاست ({$url}): {$err}");
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("پاسخ غیر JSON از سرور هاست (HTTP {$httpCode}): " . substr((string) $raw, 0, 200));
    }

    return $decoded;
}

/**
 * ساخت نمونه پرووایدر بر اساس نام
 */
function resolveProvider(string $name, array $config): ProviderInterface
{
    $pConf = $config['providers'][$name] ?? [];
    $timeouts = $config['timeouts'] ?? [];

    return match ($name) {
        'osm' => new OsmProvider($pConf, (int) ($timeouts['overpass_timeout'] ?? 90)),
        'gosom' => new GosomProvider(
            $pConf,
            (int) ($timeouts['gosom_request_timeout'] ?? 12),
            (int) ($timeouts['gosom_poll_interval_sec'] ?? 5),
            (int) ($timeouts['gosom_max_wait_sec'] ?? 180)
        ),
        'csv' => new CsvProvider($pConf),
        'neshan' => new NeshanProvider($pConf),
        'wikidata' => new WikidataProvider($pConf),
        'google_places' => new GooglePlacesProvider($pConf),
        'balad' => new BaladProvider(),
        default => throw new RuntimeException("پرووایدر ناشناخته: {$name}"),
    };
}

echo "=== شروع اجرای collector-remote (مدل Pull) ===\n";
echo "سرور هدف: {$serverUrl}\n";

try {
    // ۱. دریافت Job بعدی از صف هاست
    $nextRes = callHostApi(
        'GET',
        $serverUrl . '/api/v1/collector/jobs/next',
        $bearerToken,
        $connectTimeout,
        $serverTimeout
    );

    if (empty($nextRes['ok'])) {
        echo "[خطا از سرور]: " . ($nextRes['error'] ?? 'پاسخ نامعتبر') . "\n";
        exit(1);
    }

    if (empty($nextRes['has_job']) || empty($nextRes['job'])) {
        echo "[صف خالی است]: هیچ Job آماده‌ای برای اجرا در صف سرور وجود ندارد.\n";
        exit(0);
    }

    $job = $nextRes['job'];
    $jobId = (int) $job['id'];
    $providerName = (string) $job['provider'];
    $cityName = (string) ($job['city_name_fa'] ?? '');

    echo sprintf(
        "[Job #%d دریافت شد]: شهر «%s» | پرووایدر: %s | کوئری‌ها: %s\n",
        $jobId,
        $cityName,
        $providerName,
        implode('، ', (array) ($job['queries'] ?? []))
    );

    // ۲. اجرای جستجو توسط پرووایدر
    try {
        $provider = resolveProvider($providerName, $config);
        $items = $provider->search($job);
        $totalFound = count($items);
        echo "[یافت شد]: {$totalFound} رکورد از پرووایدر «{$providerName}» دریافت گردید.\n";

        if ($totalFound === 0) {
            $ingestRes = callHostApi(
                'POST',
                $serverUrl . '/api/v1/collector/ingest',
                $bearerToken,
                $connectTimeout,
                $serverTimeout,
                [
                    'job_id' => $jobId,
                    'provider' => $providerName,
                    'items' => [],
                    'is_final' => true,
                ]
            );
            echo "[پایان Job #{$jobId}]: هیچ رکوردی یافت نشد و وضعیت Job به done تغییر یافت.\n";
            exit(0);
        }

        // ۳. ارسال دسته‌ای در بسته‌های حداکثر ۲۰۰ آیتم به /api/v1/collector/ingest
        $chunks = array_chunk($items, $batchSize);
        $totalChunks = count($chunks);

        foreach ($chunks as $idx => $chunk) {
            $isFinal = ($idx === $totalChunks - 1);
            $ingestRes = callHostApi(
                'POST',
                $serverUrl . '/api/v1/collector/ingest',
                $bearerToken,
                $connectTimeout,
                $serverTimeout,
                [
                    'job_id' => $jobId,
                    'provider' => $providerName,
                    'items' => $chunk,
                    'is_final' => $isFinal,
                ]
            );

            $summary = $ingestRes['summary'] ?? [];
            echo sprintf(
                " -> بسته %d/%d ارسال شد | جدید: %d | تکراری/به‌روزشده: %d | خطا: %d\n",
                $idx + 1,
                $totalChunks,
                (int) ($summary['inserted'] ?? 0),
                (int) ($summary['duplicates'] ?? 0),
                (int) ($summary['errors'] ?? 0)
            );
        }

        echo "=== Job #{$jobId} («{$cityName}») با موفقیت تا انتها اجرا و در سرور ثبت شد ===\n";
    } catch (Throwable $provEx) {
        $code = (int) $provEx->getCode();
        $isBlocked = in_array($code, [403, 429], true) || str_contains(strtolower($provEx->getMessage()), 'captcha');

        echo "[خطای پرووایدر {$providerName}]: " . $provEx->getMessage() . "\n";

        $failRes = callHostApi(
            'POST',
            $serverUrl . '/api/v1/collector/ingest',
            $bearerToken,
            $connectTimeout,
            $serverTimeout,
            [
                'job_id' => $jobId,
                'provider' => $providerName,
                'provider_error' => $provEx->getMessage(),
                'provider_http_code' => $code > 0 ? $code : 500,
                'is_blocked' => $isBlocked,
            ]
        );

        echo "[پاسخ سرور به خطای پرووایدر]: " . ($failRes['message'] ?? 'ثبت شد') . "\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "[خطای بحرانی]: " . $e->getMessage() . "\n";
    exit(1);
}
