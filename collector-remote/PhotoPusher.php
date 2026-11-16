<?php
declare(strict_types=1);

namespace CollectorRemote;

/**
 * S30 — پس‌گیری عکس کسب‌وکارها روی کامپیوتر شخصی مدیر
 *
 * اجرا:  php collector-remote/run.php photos --limit=10
 *
 * چرخه:
 * ۱. GET /api/v1/collector/photos/backfill → لیست کسب‌وکارهای بی‌عکس + URL عکس‌های
 *    یافت‌شده در منابع (آپی‌فای/گوسوم) — هیچ عکسی دانلود نمی‌شود.
 * ۲. دانلود هر عکس روی همین PC (با پارامتر اندازهٔ گوگل =w1600 برای نسخهٔ بهینه)
 * ۳. پوش multipart به POST /api/v1/collector/photo → هاست فحش MIME + تبدیل WebP + لینک
 * ۴. پیشرفت در storage/photos-progress.json برای resume (عکس‌های تکراری رد می‌شوند)
 */
final class PhotoPusher
{
    private const MAX_PHOTO_BYTES = 8 * 1024 * 1024; // سقف سمت هاست 8MB است

    /**
     * @param array<string, mixed> $photos بخش photos از config
     */
    public static function run(
        string $serverUrl,
        string $bearerToken,
        int $connectTimeout,
        int $serverTimeout,
        array $photos
    ): int {
        $limit = max(1, min(100, (int) ($photos['limit'] ?? 10)));
        $downloadTimeout = (int) ($photos['download_timeout'] ?? 45);
        $sleepMs = max(0, (int) ($photos['sleep_ms'] ?? 800));
        $width = (int) ($photos['width'] ?? 1600);
        $progressFile = __DIR__ . '/storage/photos-progress.json';

        $progress = self::loadProgress($progressFile);

        echo "=== شروع پس‌گیری عکس (PhotoPusher) ===\n";
        echo "درخواست {$limit} کسب‌وکار بی‌عکس از سرور...\n";

        $list = self::jsonRequest(
            'GET',
            $serverUrl . '/api/v1/collector/photos/backfill?limit=' . $limit,
            $bearerToken,
            $connectTimeout,
            $serverTimeout
        );
        if (empty($list['ok'])) {
            echo "[خطا] پاسخ بک‌فیل نامعتبر است: " . ($list['error'] ?? '?') . "\n";
            return 1;
        }

        $candidates = is_array($list['candidates'] ?? null) ? $list['candidates'] : [];
        if ($candidates === []) {
            echo "کاندیدی برای پس‌گیری نیست — همهٔ کسب‌وکارهای اسکن‌شده عکس دارند یا منبعی عکسی ندارد.\n";
            echo "بی‌عکس (کل): " . (int) ($list['total_without_photo'] ?? 0) . "\n";
            return 0;
        }

        echo sprintf(
            "بی‌عکس (کل): %d | اسکن‌شده: %d | کاندید دریافتی: %d\n",
            (int) ($list['total_without_photo'] ?? 0),
            (int) ($list['scanned'] ?? 0),
            count($candidates)
        );

        $pushed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($candidates as $c) {
            $businessId = (int) ($c['business_id'] ?? 0);
            $name = (string) ($c['name'] ?? '');
            $attribution = (string) ($c['attribution'] ?? 'Google Maps');
            $urls = is_array($c['photos'] ?? null) ? $c['photos'] : [];
            if ($businessId <= 0 || $urls === []) continue;

            echo sprintf("\n[%d] %s — %d عکس کاندید\n", $businessId, $name, count($urls));

            foreach ($urls as $rawUrl) {
                if (!is_string($rawUrl) || trim($rawUrl) === '') continue;
                $url = self::applySizeParam(trim($rawUrl), $width);

                $key = $businessId . '|' . md5(trim($rawUrl));
                if (isset($progress[$key])) {
                    $skipped++;
                    echo "  - رد شد (قبلاً انجام شده)\n";
                    continue;
                }

                $tmp = tempnam(sys_get_temp_dir(), 'hhxphoto_');
                if ($tmp === false) {
                    echo "  [خطا] ساخت فایل موقت ناموفق بود\n";
                    $failed++;
                    continue;
                }

                $err = self::download($url, $tmp, $downloadTimeout);
                if ($err !== null) {
                    echo "  [خطای دانلود] {$err}\n";
                    @unlink($tmp);
                    $failed++;
                    continue;
                }

                $size = (int) @filesize($tmp);
                if ($size <= 0 || $size > self::MAX_PHOTO_BYTES) {
                    echo "  [رد] حجم فایل نامناسب ({$size} bytes)\n";
                    @unlink($tmp);
                    $failed++;
                    continue;
                }

                $res = self::pushPhoto(
                    $serverUrl . '/api/v1/collector/photo',
                    $bearerToken,
                    $connectTimeout,
                    $serverTimeout,
                    $tmp,
                    $businessId,
                    trim($rawUrl),
                    $attribution,
                    $name
                );
                @unlink($tmp);

                $httpCode = (int) ($res['http_code'] ?? 0);
                if ($httpCode === 401) {
                    echo "  [توقف] توکن نامعتبر است؛ bearer_token در config.php را با پنل تطبیق دهید.\n";
                    return 1;
                }

                if (!empty($res['body']['ok'])) {
                    $cover = !empty($res['body']['set_as_cover']) ? ' | کاور شد' : '';
                    $dup = !empty($res['body']['duplicate']) ? ' (تکراری)' : '';
                    echo "  + پوش شد{$dup}{$cover}\n";
                    $progress[$key] = 1;
                    self::saveProgress($progressFile, $progress);
                    $pushed++;
                } else {
                    $failed++;
                    echo "  [خطای سرور " . $httpCode . "] " . ((string) ($res['body']['error'] ?? 'نامشخص')) . "\n";
                }

                if ($sleepMs > 0) usleep($sleepMs * 1000);
            }
        }

        echo sprintf(
            "\n=== پایان: %d عکس پوش شد | %d رد (تکرار) | %d خطا ===\n",
            $pushed,
            $skipped,
            $failed
        );
        return $failed > 0 && $pushed === 0 ? 1 : 0;
    }

    /** پارامتر اندازهٔ گوگل‌فوتو: فقط برای میزبان‌های googleusercontent (مثل =s0 → =w1600) */
    private static function applySizeParam(string $url, int $width): string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '' || !str_contains($host, 'googleusercontent')) {
            return $url;
        }
        $prefix = substr($url, 0, (int) strrpos($url, '/'));
        $last = substr($url, (int) strrpos($url, '/') + 1);
        $eq = strrpos($last, '=');
        if ($eq !== false && $eq > 0) {
            return $prefix . '/' . substr($last, 0, $eq) . '=w' . $width;
        }
        return $url . '=w' . $width;
    }

    /** دانلود عکس به فایل؛ خروجی null یعنی موفق، رشته یعنی پیام خطا */
    private static function download(string $url, string $dest, int $timeout): ?string
    {
        $fh = @fopen($dest, 'wb');
        if ($fh === false) {
            return 'فایل مقصد باز نشد';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_MAXFILESIZE => self::MAX_PHOTO_BYTES,
            CURLOPT_USERAGENT => 'HamiHubX-PhotoPusher/1.0 (+https://hamihubx.ir)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false) return 'cURL: ' . $err;
        if ($code < 200 || $code >= 300) return "HTTP {$code}";
        if (!str_starts_with(strtolower($type), 'image/')) return 'محتوا تصویر نیست (' . $type . ')';
        return null;
    }

    /** پوش multipart یک عکس به هاست */
    private static function pushPhoto(
        string $url,
        string $bearerToken,
        int $connectTimeout,
        int $serverTimeout,
        string $filePath,
        int $businessId,
        string $sourceUrl,
        string $attribution,
        string $altText
    ): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $serverTimeout,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $bearerToken,
            ],
            CURLOPT_POSTFIELDS => [
                'business_id' => (string) $businessId,
                'source_url' => $sourceUrl,
                'attribution' => $attribution,
                'alt_text' => $altText,
                'image' => new \CURLFile($filePath),
            ],
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['http_code' => 0, 'body' => ['ok' => false, 'error' => 'cURL: ' . $err]];
        }
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            return ['http_code' => $httpCode, 'body' => ['ok' => false, 'error' => 'پاسخ غیر JSON از هاست']];
        }
        return ['http_code' => $httpCode, 'body' => $body];
    }

    private static function jsonRequest(
        string $method,
        string $url,
        string $bearerToken,
        int $connectTimeout,
        int $serverTimeout
    ): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $serverTimeout,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $bearerToken,
            ],
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'error' => 'cURL: ' . $err];
        }
        $body = json_decode((string) $raw, true);
        return is_array($body) ? $body : ['ok' => false, 'error' => 'پاسخ غیر JSON'];
    }

    /** @return array<string, int> */
    private static function loadProgress(string $file): array
    {
        if (!is_file($file)) return [];
        $decoded = json_decode((string) @file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, int> $progress */
    private static function saveProgress(string $file, array $progress): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // حداکثر ۵۰۰۰ رکورد نگه می‌داریم تا فایل بی‌نهایت بزرگ نشود
        if (count($progress) > 5000) {
            $progress = array_slice($progress, -5000, null, true);
        }
        @file_put_contents($file, json_encode($progress, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
