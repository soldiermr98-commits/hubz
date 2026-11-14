<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use Throwable;

/**
 * S24 — پایش سلامت سیستم برای لانچ فنی و پس از آن.
 * همهٔ بررسی‌ها فقط-خواندنی‌اند و هیچ چیز را تغییر نمی‌دهند؛ خروجی برای پنل ادمین
 * و اسکریپت CLI است: هر چک وضعیت ok/warn/error و توضیح فارسی دارد.
 */
final class HealthService
{
    /** @return array<string, mixed> */
    public static function run(): array
    {
        $checks = [];
        $checks[] = self::checkDatabase();
        $checks[] = self::checkCron();
        $checks[] = self::checkAiQueue();
        $checks[] = self::checkBackups();
        $checks[] = self::checkStorageWritable();
        $checks[] = self::checkMigrations();
        $checks[] = self::checkPublicContent();

        $statuses = array_column($checks, 'status');
        $overall = in_array('error', $statuses, true) ? 'error' : (in_array('warn', $statuses, true) ? 'warn' : 'ok');

        return [
            'ok' => $overall !== 'error',
            'overall' => $overall,
            'checks' => $checks,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @return array{id:string,label:string,status:string,detail:string} */
    private static function checkDatabase(): array
    {
        try {
            $row = DB::fetchOne('SELECT COUNT(*) AS c, NOW() AS db_now FROM businesses');
            $count = (int) ($row['c'] ?? 0);
            return ['id' => 'database', 'label' => 'اتصال دیتابیس', 'status' => 'ok',
                'detail' => sprintf('اتصال برقرار است؛ %d کسب‌وکار ثبت شده. زمان سرور دیتابیس: %s', $count, (string) $row['db_now'])];
        } catch (Throwable $e) {
            return ['id' => 'database', 'label' => 'اتصال دیتابیس', 'status' => 'error',
                'detail' => 'دیتابیس پاسخ نمی‌دهد: ' . mb_substr($e->getMessage(), 0, 160)];
        }
    }

    private static function checkCron(): array
    {
        $row = DB::fetchOne("SELECT v FROM settings WHERE k = 'sponsor_last_check'");
        $last = (string) ($row['v'] ?? '');
        if ($last === '') {
            return ['id' => 'cron', 'label' => 'کرون نگهداری', 'status' => 'warn',
                'detail' => 'هنوز هیچ اجرایی ثبت نشده؛ کرون cron/worker.php را طبق راهنمای تب تنظیمات فعال کنید.'];
        }
        $ageHours = (time() - strtotime($last)) / 3600;
        if ($ageHours > 24) {
            return ['id' => 'cron', 'label' => 'کرون نگهداری', 'status' => 'error',
                'detail' => sprintf('آخرین اجرای کرون %.1f ساعت پیش بوده؛ احتمالاً کرون هاست غیرفعال شده است.', $ageHours)];
        }
        if ($ageHours > 1.5) {
            return ['id' => 'cron', 'label' => 'کرون نگهداری', 'status' => 'warn',
                'detail' => sprintf('آخرین اجرای کرون %.1f ساعت پیش بوده؛ بازهٔ انتظار کرون را بررسی کنید.', $ageHours)];
        }
        return ['id' => 'cron', 'label' => 'کرون نگهداری', 'status' => 'ok',
            'detail' => sprintf('آخرین اجرا %s (حدود %.1f ساعت پیش).', $last, $ageHours)];
    }

    private static function checkAiQueue(): array
    {
        try {
            $row = DB::fetchOne("SELECT SUM(status = 'queued') AS q, SUM(status = 'claimed') AS cl, SUM(status = 'done') AS dn, SUM(status = 'failed') AS f FROM ai_tasks");
            $queued = (int) ($row['q'] ?? 0);
            $claimed = (int) ($row['cl'] ?? 0);
            $done = (int) ($row['dn'] ?? 0);
            $failed = (int) ($row['f'] ?? 0);
            if ($claimed > 0) {
                return ['id' => 'ai_queue', 'label' => 'صف هوش مصنوعی', 'status' => 'warn',
                    'detail' => sprintf('%d تسک در حال اجرا مانده؛ اگر بیش از ۱۵ دقیقه است، نگهداری خودکار به صف برمی‌گرداند.', $claimed)];
            }
            if ($failed > 20) {
                return ['id' => 'ai_queue', 'label' => 'صف هوش مصنوعی', 'status' => 'warn',
                    'detail' => sprintf('%d تسک ردشدهٔ تجمعی دارید؛ دلیل رد شدن‌ها در پنل صف AI قابل مشاهده است.', $failed)];
            }
            return ['id' => 'ai_queue', 'label' => 'صف هوش مصنوعی', 'status' => 'ok',
                'detail' => sprintf('صف سالم است: %d در صف، %d انجام‌شده، %d ردشده.', $queued, $done, $failed)];
        } catch (Throwable) {
            return ['id' => 'ai_queue', 'label' => 'صف هوش مصنوعی', 'status' => 'warn',
                'detail' => 'جدول ai_tasks در دسترس نیست؛ مایگریشن‌ها را اجرا کنید.'];
        }
    }

    private static function checkBackups(): array
    {
        $row = DB::fetchOne("SELECT v FROM settings WHERE k = 'backup_last_run'");
        $last = (string) ($row['v'] ?? '');
        $dir = BASE_PATH . '/' . BackupService::directory();
        $files = is_dir($dir) ? (glob($dir . '/hamihub-db-*.sql.gz') ?: []) : [];
        if ($last === '' && !$files) {
            return ['id' => 'backups', 'label' => 'بکاپ دیتابیس', 'status' => 'warn',
                'detail' => 'هنوز هیچ بکاپی ساخته نشده؛ از پنل «بکاپ دستی» را بزنید یا کرون را فعال کنید.'];
        }
        $ageHours = $last !== '' ? (time() - strtotime($last)) / 3600 : null;
        if ($ageHours !== null && $ageHours > 48) {
            return ['id' => 'backups', 'label' => 'بکاپ دیتابیس', 'status' => 'error',
                'detail' => sprintf('آخرین بکاپ %.1f ساعت پیش بوده؛ بکاپ خودکار کار نمی‌کند.', $ageHours)];
        }
        $totalBytes = 0;
        foreach ($files as $f) {
            $s = @filesize($f);
            if ($s !== false) $totalBytes += $s;
        }
        return ['id' => 'backups', 'label' => 'بکاپ دیتابیس', 'status' => 'ok',
            'detail' => sprintf('%d فایل بکاپ روی هاست (%s).%s', count($files), self::formatBytes($totalBytes),
                $ageHours !== null ? sprintf(' آخرین اجرا: %s.', $last) : '')];
    }

    private static function checkStorageWritable(): array
    {
        $dir = BASE_PATH . '/' . BackupService::directory();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['id' => 'storage', 'label' => 'دسترسی نوشتن storage', 'status' => 'error',
                'detail' => 'پوشهٔ storage/backups ساخته نشد؛ دسترسی نوشتن پوشهٔ storage را بررسی کنید.'];
        }
        $probe = $dir . '/.write-probe-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'ok') === false) {
            return ['id' => 'storage', 'label' => 'دسترسی نوشتن storage', 'status' => 'error',
                'detail' => 'نوشتن آزمایشی در storage/backups ناموفق بود؛ بکاپ‌ها ذخیره نمی‌شوند.'];
        }
        @unlink($probe);
        return ['id' => 'storage', 'label' => 'دسترسی نوشتن storage', 'status' => 'ok',
            'detail' => 'پوشهٔ بکاپ نوشتنی است.'];
    }

    private static function checkMigrations(): array
    {
        try {
            $hasCommentsTable = DB::fetchOne("SHOW TABLES LIKE 'business_comments'") !== null;
            $hasBackupsTable = DB::fetchOne("SHOW TABLES LIKE 'system_backups'") !== null;
            $missing = [];
            if (!$hasBackupsTable) $missing[] = 'system_backups (۰۲۲)';
            if (!$hasCommentsTable) $missing[] = 'business_comments (۰۲۰/۰۲۱)';
            if ($missing) {
                return ['id' => 'migrations', 'label' => 'مایگریشن‌ها', 'status' => 'warn',
                    'detail' => 'این جدول‌ها یافت نشد: ' . implode('، ', $missing) . ' — دکمهٔ «اجرای مایگریشن‌ها» را بزنید.'];
            }
            return ['id' => 'migrations', 'label' => 'مایگریشن‌ها', 'status' => 'ok',
                'detail' => 'جدول‌های شناخته‌شدهٔ نسخهٔ فعلی همگی موجودند.'];
        } catch (Throwable $e) {
            return ['id' => 'migrations', 'label' => 'مایگریشن‌ها', 'status' => 'error',
                'detail' => 'بررسی مایگریشن‌ها ناموفق بود: ' . mb_substr($e->getMessage(), 0, 160)];
        }
    }

    private static function checkPublicContent(): array
    {
        $index = BASE_PATH . '/public_html/index.php';
        $api = BASE_PATH . '/public_html/api/index.php';
        $missing = [];
        if (!is_file($index)) $missing[] = 'public_html/index.php';
        if (!is_file($api)) $missing[] = 'public_html/api/index.php';
        if ($missing) {
            return ['id' => 'public', 'label' => 'فایل‌های عمومی سایت', 'status' => 'error',
                'detail' => 'فایل‌های یافت‌نشده: ' . implode('، ', $missing) . ' — نصب بسته ناقص است.'];
        }
        return ['id' => 'public', 'label' => 'فایل‌های عمومی سایت', 'status' => 'ok',
            'detail' => 'فایل‌های ورودی سایت و API سر جایشان هستند.'];
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 2) . ' MB';
        return round($bytes / 1073741824, 2) . ' GB';
    }
}
