<?php
declare(strict_types=1);

// Run from CLI only; schedule this file using the PHP binary provided by the host.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$basePath = dirname(__DIR__);
require_once $basePath . '/app/Core/Bootstrap.php';
\App\Core\Bootstrap::init($basePath);

/**
 * S28 — بهداشت لاگ کرون و خروجیِ بی‌صدا در حالت عادی.
 *
 * تفاوت با نسخه‌های قبلی:
 * ۱) ضربانِ «کرون زنده است» به‌جای لاگِ الحاقی، در فایل ثابت‌حجم storage/cron-last-run.txt
 *    بازنویسی می‌شود (هر اجرا همان چند بایت را بازنویسی می‌کند؛ هیچ‌وقت رشد نمی‌کند).
 * ۲) وقتی هیچ اتفاق واقعی نیفتاده (صف جمع‌آوری خالی، بکاپ در موعد نیست، Apify در انتظار،
 *    نگهداری بدون تغییر) هیچ خروجی‌ای چاپ نمی‌شود؛ پس cron.log دیگر هر دقیقه شلوغ نمی‌شود.
 * ۳) فقط رویدادهای واقعی — اجرای جاب جمع‌آوری، گرفتن بکاپ روزانه، انتقال جایگاه اسپانسری،
 *    آزادسازی جابِ قفل‌شده، پیرایش لاگ آمار، و هرگونه خطا — به‌صورت یک خط JSON چاپ می‌شوند.
 * ۴) storage/cron.log و storage/logs/worker.log (میراث نسخهٔ S0) بزرگ‌تر از ۱ مگابایت
 *    نمی‌مانند: نسخهٔ فعلی به پسوند .old چرخش می‌کند؛ حداکثر فضای هر کدام ۲ مگابایت است.
 */

const CRON_LOG_MAX_BYTES = 1048576;

$rotateLog = static function (string $path): void {
    if (!is_file($path)) {
        return;
    }
    clearstatcache(true, $path);
    if ((int) @filesize($path) <= CRON_LOG_MAX_BYTES) {
        return;
    }
    $rotated = $path . '.old';
    if (is_file($rotated)) {
        @unlink($rotated);
    }
    @rename($path, $rotated);
};

$rotateLog($basePath . '/storage/cron.log');
$rotateLog($basePath . '/storage/logs/worker.log');

// ضربان زنده‌بودن کرون — بازنویسی، نه الحاق؛ حجم فایل همیشه ثابت است.
@file_put_contents(
    $basePath . '/storage/cron-last-run.txt',
    date('Y-m-d H:i:s P') . PHP_EOL
);

$exitCode = 0;
try {
    if (!\App\Core\WorkerLock::acquire('cron')) {
        // اجرای قبلی هنوز در جریان است؛ اتفاق عادی است و نباید لاگ بگیرد.
        exit(0);
    }
    try {
        $summary = \App\Services\MaintenanceService::run();
        if (class_exists(\App\Services\CollectorRunner::class)) {
            $summary['collector'] = \App\Services\CollectorRunner::runNext();
        }
        try {
            $summary['apify'] = \App\Services\ApifyAutomationService::configured()->tick();
        } catch (Throwable $e) {
            error_log('Apify cron failed: ' . get_class($e));
            $summary['apify'] = ['status'=>'error'];
        }
        // S24: بکاپ روزانهٔ خودکار دیتابیس (بیش از یک‌بار در ۲۰ ساعت اجرا نمی‌شود؛
        // خطای بکاپ بقیهٔ کرون را ناموفق نمی‌کند و فقط در لاگ و system_backups ثبت می‌شود).
        try {
            $summary['backup'] = \App\Services\BackupService::runDue('cron');
        } catch (Throwable $e) {
            error_log('Backup cron failed: ' . get_class($e));
            $summary['backup'] = ['status'=>'error'];
        }
        $ok = ($summary['status'] ?? '') !== 'cache_error'
            && ($summary['collector']['status'] ?? '') !== 'failed'
            && !in_array($summary['apify']['status'] ?? '', ['error','fetch_error','preflight_error','start_unknown'], true);

        $events = cronEvents($summary);
        if ($events !== []) {
            echo json_encode(['ok' => $ok, 'at' => date('Y-m-d H:i:s P'), 'events' => $events], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
        if (!$ok) $exitCode = 1;
    } finally {
        \App\Core\WorkerLock::release('cron');
    }
} catch (Throwable $e) {
    error_log('HamiHub cron failed: ' . get_class($e));
    fwrite(STDERR, "Cron failed or its result is uncertain; see server logs.\n");
    $exitCode = 1;
}
exit($exitCode);

/**
 * فقط بخش‌هایی از خلاصه را برمی‌گرداند که «اتفاق واقعی» را نشان می‌دهند.
 * حالت‌های روتین و بی‌خبر (صف خالی، بکاپِ در موعد نبودن، انتظار Apify و ...)
 * حذف می‌شوند تا لاگ فقط حاوی رویداد باشد.
 */
function cronEvents(array $summary): array
{
    $events = [];

    $maintenance = [];
    foreach (['status', 'sponsor_transitions', 'flushed_files', 'released_jobs', 'collector_recovery_skipped', 'released_ai_tasks', 'analytics_prune'] as $key) {
        if (array_key_exists($key, $summary)) {
            $maintenance[$key] = $summary[$key];
        }
    }
    $maintenanceInteresting =
        (($maintenance['status'] ?? 'ok') !== 'ok')
        || (int) ($maintenance['sponsor_transitions'] ?? 0) > 0
        || (int) ($maintenance['flushed_files'] ?? 0) > 0
        || (int) ($maintenance['released_jobs'] ?? 0) > 0
        || (int) ($maintenance['released_ai_tasks'] ?? 0) > 0
        || (($maintenance['analytics_prune']['status'] ?? 'skipped') !== 'skipped');
    if ($maintenanceInteresting) {
        $events['maintenance'] = $maintenance;
    }

    $collector = $summary['collector'] ?? null;
    if (is_array($collector) && !empty($collector['ran'])) {
        $events['collector'] = [
            'job_id'  => $collector['job_id'] ?? null,
            'status'  => $collector['status'] ?? null,
            'summary' => $collector['summary'] ?? null,
        ];
    }

    $apifyStatus = (string) ($summary['apify']['status'] ?? 'disabled');
    $apifyIdle = ['disabled', 'busy', 'waiting', 'starting'];
    if (!in_array($apifyStatus, $apifyIdle, true)) {
        $events['apify'] = $summary['apify'];
    }

    $backupStatus = (string) ($summary['backup']['status'] ?? 'skipped');
    $backupIdle = ['skipped', 'busy'];
    if (!in_array($backupStatus, $backupIdle, true)) {
        $events['backup'] = $summary['backup'];
    }

    return $events;
}
