<?php
declare(strict_types=1);

namespace CollectorRemote\Providers;

use RuntimeException;

/**
 * پرووایدر Gosom (فراخوانی REST محلی ابزار gosom/google-maps-scraper روی کامپیوتر شما)
 * طبق اصلاحیه ۲:
 * - مدل Async: ثبت Job با POST
 * - سپس Polling هر ۵ ثانیه برای بررسی وضعیت (هر درخواست کوتاه با TIMEOUT=12)
 * - سقف کل انتظار هر Job قابل تنظیم (پیش‌فرض ۱۸۰ ثانیه)
 */
final class GosomProvider implements ProviderInterface
{
    private array $config;
    private int $reqTimeout;
    private int $pollInterval;
    private int $maxWaitSec;

    public function __construct(
        array $config,
        int $reqTimeout = 12,
        int $pollInterval = 5,
        int $maxWaitSec = 180
    ) {
        $this->config = $config;
        $this->reqTimeout = $reqTimeout;
        $this->pollInterval = $pollInterval;
        $this->maxWaitSec = $maxWaitSec;
    }

    public function name(): string
    {
        return 'gosom';
    }

    public function search(array $job): array
    {
        $baseUrl = rtrim((string) ($this->config['base_url'] ?? 'http://127.0.0.1:8080'), '/');
        $cityName = (string) ($job['city_name_fa'] ?? 'رشت');
        $queries = isset($job['queries']) && is_array($job['queries']) ? $job['queries'] : ['کافه'];

        $keywords = [];
        foreach ($queries as $q) {
            $keywords[] = trim((string) $q) . ' در ' . $cityName;
        }

        // ۱. ثبت Async Job در سرویس محلی gosom با POST (CONNECTTIMEOUT=5, TIMEOUT=12)
        $createPayload = [
            'name' => 'hhx_job_' . ($job['id'] ?? time()),
            'keywords' => $keywords,
            'lang' => 'fa',
            'zoom' => 14,
            'lat' => (string) ($job['lat'] ?? '37.2808'),
            'lon' => (string) ($job['lng'] ?? '49.5830'),
            'fast_mode' => false,
            'depth' => 1,
        ];

        $createRes = $this->httpJson('POST', $baseUrl . '/api/v1/jobs', $createPayload);
        $remoteJobId = (string) ($createRes['id'] ?? $createRes['job_id'] ?? '');

        if ($remoteJobId === '') {
            throw new RuntimeException('سرویس محلی gosom شناسه Job برنگرداند یا روی پورت محلی فعال نیست.', 503);
        }

        // ۲. Polling هر ۵ ثانیه تا سقف ۱۸۰ ثانیه (طبق اصلاحیه ۲)
        $startTime = time();
        while ((time() - $startTime) < $this->maxWaitSec) {
            sleep($this->pollInterval);

            $statusRes = $this->httpJson('GET', $baseUrl . '/api/v1/jobs/' . rawurlencode($remoteJobId));
            $status = strtolower((string) ($statusRes['status'] ?? $statusRes['state'] ?? ''));

            if (in_array($status, ['finished', 'done', 'completed', 'ok'], true)) {
                $rawItems = $statusRes['results'] ?? $statusRes['data'] ?? [];
                if (!is_array($rawItems)) {
                    return [];
                }
                return $this->mapGosomItems($rawItems, $cityName);
            }

            if (in_array($status, ['failed', 'error'], true)) {
                throw new RuntimeException('اجرای Job در سرویس محلی gosom با خطا مواجه شد.', 500);
            }
        }

        throw new RuntimeException(
            sprintf('زمان انتظار برای سرویس محلی gosom از سقف %d ثانیه عبور کرد.', $this->maxWaitSec),
            504
        );
    }

    public function details(string $sourceId): ?array
    {
        return null;
    }

    /**
     * تبدیل خروجی gosom به فرمت استاندارد ProviderInterface
     */
    private function mapGosomItems(array $rawItems, string $cityName): array
    {
        $out = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['title'] ?? $item['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $phones = [];
            if (!empty($item['phone'])) {
                $phones[] = (string) $item['phone'];
            }

            $photos = [];
            if (!empty($item['thumbnail'])) {
                $photos[] = (string) $item['thumbnail'];
            }

            $out[] = [
                'name' => $name,
                'category' => (string) ($item['category'] ?? 'کافه و رستوران'),
                'address' => trim((string) ($item['address'] ?? $cityName)),
                'lat' => isset($item['latitude']) ? (float) $item['latitude'] : null,
                'lng' => isset($item['longitude']) ? (float) $item['longitude'] : null,
                'phones' => $phones,
                'hours' => is_string($item['open_hours'] ?? null) ? $item['open_hours'] : '',
                'rating' => isset($item['review_rating']) ? (float) $item['review_rating'] : null,
                'reviews_count' => isset($item['review_count']) ? (int) $item['review_count'] : 0,
                'photo_urls' => $photos,
                'source' => 'gosom',
                'source_id' => (string) ($item['place_id'] ?? $item['cid'] ?? md5($name . ($item['address'] ?? ''))),
            ];
        }
        return $out;
    }

    private function httpJson(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->reqTimeout,
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode >= 400) {
            throw new RuntimeException(
                sprintf('خطای ارتباط با سرویس محلی Gosom (%s - HTTP %d): %s', $url, $httpCode, $err ?: 'عدم پاسخ'),
                $httpCode ?: 503
            );
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
