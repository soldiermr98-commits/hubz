<?php
declare(strict_types=1);

namespace App\Core;

/**
 * محدودساز نرخ سبک و فایل‌محور برای اندپوینت‌های عمومیِ بدون CSRF (مثل beacon آمار).
 *
 * - پنجرهٔ ثابت (fixed window) بر پایهٔ IP و نام سطل
 * - شمارنده‌ها در storage/private/ratelimit با flock اتمیک نگه‌داری می‌شوند
 * - هر شکست I/O «مجاز» است (fail-open) تا هیچ‌گاه رابط عمومی سایت را نشکند
 * - فایل‌های پنجره‌های گذشته به‌صورت فرصت‌طلبانه پاک‌سازی می‌شوند
 */
final class RateLimit
{
    /**
     * آیا این درخواست مجاز است؟
     */
    public static function allow(string $bucket, string $ip, int $maxRequests, int $windowSeconds): bool
    {
        if ($ip === '' || $maxRequests <= 0 || $windowSeconds <= 0) {
            return true;
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $dir = $base . '/storage/private/ratelimit';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return true;
        }
        $window = (int) floor(time() / $windowSeconds);
        $hash = hash('sha256', $bucket . '|' . $ip);
        $file = $dir . '/' . $hash . '_' . $window . '.json';

        $allowed = true;
        $fp = @fopen($file, 'c+');
        if ($fp !== false) {
            if (@flock($fp, LOCK_EX)) {
                $raw = stream_get_contents($fp);
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                $count = (is_array($decoded) && isset($decoded['count'])) ? (int) $decoded['count'] : 0;
                $count++;
                $allowed = $count <= $maxRequests;
                rewind($fp);
                @ftruncate($fp, 0);
                @fwrite($fp, (string) json_encode(['count' => $count], JSON_UNESCAPED_UNICODE));
                @fflush($fp);
                @flock($fp, LOCK_UN);
            }
            @fclose($fp);
        }

        // پاک‌سازی فرصت‌طلبانهٔ فایل‌های پنجره‌های گذشته (حدود ۲٪ درخواست‌ها، با سقف اسکن)
        if (random_int(1, 50) === 1) {
            self::gcStaleWindows($dir, $windowSeconds);
        }

        return $allowed;
    }

    private static function gcStaleWindows(string $dir, int $windowSeconds): void
    {
        try {
            $it = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
            $now = time();
            $seen = 0;
            foreach ($it as $fileInfo) {
                if (++$seen > 400) {
                    break;
                }
                if (!$fileInfo->isFile()) {
                    continue;
                }
                if ($now - $fileInfo->getMTime() > $windowSeconds * 2) {
                    @unlink($fileInfo->getPathname());
                }
            }
        } catch (\Throwable) {
            // پاک‌سازی هرگز نباید پاسخ اصلی را خراب کند
        }
    }
}
